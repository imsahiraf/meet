<?php

namespace Modules\Mailbox\ActiveSync;

use Modules\Authentication\Models\User;
use Illuminate\Support\Facades\Storage;
use Modules\Mailbox\Models\EasDevice;
use Modules\Mailbox\Models\EasState;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Models\MailAttachment;
use Modules\Mailbox\Models\MailMessage;
use Modules\Mailbox\Services\DriveArchive;
use Modules\Mailbox\Services\Mailbox;
use Modules\Mailbox\Services\MimeParser;
use Throwable;

/**
 * Exchange ActiveSync commands for mail (protocol 12.0 - 14.1), our own implementation of the open specification.
 * One instance per request: the signed-in user, the account the phone uses (null = all the user's accounts), the device.
 * Returns [http status, body bytes, content type].
 */
class Server
{
    public const VERSIONS = '12.0,12.1,14.0,14.1';

    public const COMMANDS = 'Sync,SendMail,SmartForward,SmartReply,GetAttachment,FolderSync,MoveItems,GetItemEstimate,Ping,ItemOperations,Provision,Settings';

    /** server id => [ActiveSync folder type, name shown on the phone] */
    public const FOLDERS = ['inbox' => [2, 'Inbox'], 'drafts' => [3, 'Drafts'], 'trash' => [4, 'Trash'], 'sent' => [5, 'Sent'], 'archive' => [12, 'Archive'], 'spam' => [12, 'Spam']];

    private const FILTER_DAYS = [1 => 1, 2 => 3, 3 => 7, 4 => 14, 5 => 30, 6 => 90, 7 => 180];

    private const MAX_ITEMS = 2000; // newest messages per folder a phone keeps in step with

    public function __construct(private User $user, private ?MailAccount $account, private EasDevice $device, private string $version = '14.1')
    {
    }

    public function handle(string $cmd, string $body, array $query): array
    {
        $req = in_array($cmd, ['GetAttachment'], true) || ($this->version < '14.0' && in_array($cmd, ['SendMail', 'SmartReply', 'SmartForward'], true))
            ? null : Wbxml::decode($body);
        $res = match ($cmd) {
            'FolderSync' => $this->folderSync($req),
            'Sync' => $this->sync($req),
            'GetItemEstimate' => $this->estimate($req),
            'ItemOperations' => $this->itemOperations($req),
            'GetAttachment' => $this->getAttachment((string) ($query['AttachmentName'] ?? '')),
            'MoveItems' => $this->moveItems($req),
            'SendMail', 'SmartReply', 'SmartForward' => $this->send($cmd, $req, $body, $query),
            'Settings' => $this->settings($req),
            'Provision' => $this->provision($req),
            'Ping' => $this->ping($req),
            default => [501, '', 'text/plain'],
        };

        return is_array($res) && isset($res['n']) ? [200, Wbxml::encode($res), 'application/vnd.ms-sync.wbxml'] : $res;
    }

    // ---------------- folders ----------------

    private function folderSync(?array $req): array
    {
        $key = (int) Wbxml::value($req, 'FolderHierarchy:SyncKey');
        if ($key !== 0 && $key !== (int) $this->device->folder_key) {
            return Wbxml::el('FolderHierarchy:FolderSync', [Wbxml::text('FolderHierarchy:Status', 9)]);
        }
        if ($key === 0) {
            $this->device->forceFill(['folder_key' => 1])->save();
            $adds = [];
            foreach (self::FOLDERS as $id => [$type, $name]) {
                $adds[] = Wbxml::el('FolderHierarchy:Add', [Wbxml::text('FolderHierarchy:ServerId', $id), Wbxml::text('FolderHierarchy:ParentId', '0'),
                    Wbxml::text('FolderHierarchy:DisplayName', $name), Wbxml::text('FolderHierarchy:Type', $type)]);
            }

            return Wbxml::el('FolderHierarchy:FolderSync', [Wbxml::text('FolderHierarchy:Status', 1), Wbxml::text('FolderHierarchy:SyncKey', 1),
                Wbxml::el('FolderHierarchy:Changes', array_merge([Wbxml::text('FolderHierarchy:Count', count($adds))], $adds))]);
        }

        return Wbxml::el('FolderHierarchy:FolderSync', [Wbxml::text('FolderHierarchy:Status', 1), Wbxml::text('FolderHierarchy:SyncKey', $key),
            Wbxml::el('FolderHierarchy:Changes', [Wbxml::text('FolderHierarchy:Count', 0)])]);
    }

    // ---------------- messages of a folder ----------------

    private function query(string $folder)
    {
        return MailMessage::where('user_id', $this->user->id)->when($this->account, fn ($q) => $q->where('account_id', $this->account->id))->where('folder', $folder);
    }

    private function message(string $serverId): ?MailMessage
    {
        return ctype_digit($serverId) ? MailMessage::where('user_id', $this->user->id)->when($this->account, fn ($q) => $q->where('account_id', $this->account->id))->find((int) $serverId) : null;
    }

    /** {id: flags} of the folder now, newest first. flags: 1 = read, 2 = starred */
    private function current(string $folder, int $filter): array
    {
        $q = $this->query($folder);
        if (isset(self::FILTER_DAYS[$filter])) {
            $q->where('sent_at', '>=', now()->subDays(self::FILTER_DAYS[$filter]));
        }
        $out = [];
        foreach ($q->orderByDesc('sent_at')->orderByDesc('id')->limit(self::MAX_ITEMS)->get(['id', 'seen', 'starred']) as $m) {
            $out[(string) $m->id] = ($m->seen ? 1 : 0) | ($m->starred ? 2 : 0);
        }

        return $out;
    }

    private static function flags(MailMessage $m): int
    {
        return ($m->seen ? 1 : 0) | ($m->starred ? 2 : 0);
    }

    // ---------------- Sync ----------------

    private function sync(?array $req): array
    {
        $out = [];
        foreach (Wbxml::children(Wbxml::child($req, 'AirSync:Collections'), 'AirSync:Collection') as $col) {
            $out[] = $this->syncCollection($col);
        }
        if (!$out) { // empty request = "same as last time"; we have nothing cached, ask the phone to send the full request
            return Wbxml::el('AirSync:Sync', [Wbxml::text('AirSync:Status', 13)]);
        }

        return Wbxml::el('AirSync:Sync', [Wbxml::el('AirSync:Collections', $out)]);
    }

    private function syncCollection(array $col): array
    {
        $cid = (string) Wbxml::value($col, 'AirSync:CollectionId');
        $key = (int) Wbxml::value($col, 'AirSync:SyncKey');
        $head = fn (int $status, ?int $newKey = null) => array_filter([
            Wbxml::text('AirSync:SyncKey', $newKey ?? $key), Wbxml::text('AirSync:CollectionId', $cid), Wbxml::text('AirSync:Status', $status)]);
        if (!isset(self::FOLDERS[$cid])) {
            return Wbxml::el('AirSync:Collection', $head(8));
        }
        $state = EasState::firstOrNew(['device_id' => $this->device->id, 'collection' => $cid]);
        $options = Wbxml::child($col, 'AirSync:Options');
        $filter = (int) (Wbxml::value($options, 'AirSync:FilterType') ?? $state->filter);
        if ($key === 0) {
            $state->fill(['sync_key' => 1, 'items' => '{}', 'prev_key' => null, 'prev_items' => null, 'filter' => $filter])->save();

            return Wbxml::el('AirSync:Collection', $head(1, 1));
        }
        if ($state->exists && $key === (int) $state->sync_key) {
            $base = $state->itemsArray();
        } elseif ($state->exists && $key === (int) $state->prev_key) {
            $base = $state->itemsArray(true); // the phone did not get our last answer: answer again from the state before
        } else {
            return Wbxml::el('AirSync:Collection', $head(3));
        }
        $before = $base;
        $deletesAsMoves = Wbxml::value($col, 'AirSync:DeletesAsMoves') !== '0';
        $responses = [];

        // ---- the phone's changes ----
        foreach (Wbxml::child($col, 'AirSync:Commands')['c'] ?? [] as $cmd) {
            $sid = (string) Wbxml::value($cmd, 'AirSync:ServerId');
            if ($cmd['n'] === 'AirSync:Change') {
                $m = $this->message($sid);
                if (!$m) {
                    $responses[] = Wbxml::el('AirSync:Change', [Wbxml::text('AirSync:ServerId', $sid), Wbxml::text('AirSync:Status', 8)]);
                    continue;
                }
                $data = Wbxml::child($cmd, 'AirSync:ApplicationData');
                if (($read = Wbxml::value($data, 'Email:Read')) !== null) {
                    $m->seen = $read === '1';
                }
                if ($flag = Wbxml::child($data, 'Email:Flag')) {
                    $m->starred = Wbxml::value($flag, 'Email:Status') === '2';
                }
                $m->save();
                $base[$sid] = self::flags($m);
            } elseif ($cmd['n'] === 'AirSync:Delete') {
                if ($m = $this->message($sid)) {
                    if ($m->folder === 'trash' || !$deletesAsMoves) {
                        Mailbox::purge($m);
                    } else {
                        $m->forceFill(['folder' => 'trash'])->save();
                    }
                }
                unset($base[$sid]);
            } elseif ($cmd['n'] === 'AirSync:Add') { // new items made on the phone (drafts): not kept on the server
                $responses[] = Wbxml::el('AirSync:Add', [Wbxml::text('AirSync:ClientId', (string) Wbxml::value($cmd, 'AirSync:ClientId')), Wbxml::text('AirSync:Status', 6)]);
            } elseif ($cmd['n'] === 'AirSync:Fetch') {
                $m = $this->message($sid);
                $responses[] = Wbxml::el('AirSync:Fetch', $m
                    ? [Wbxml::text('AirSync:ServerId', $sid), Wbxml::text('AirSync:Status', 1), Wbxml::el('AirSync:ApplicationData', $this->appData($m, $options))]
                    : [Wbxml::text('AirSync:ServerId', $sid), Wbxml::text('AirSync:Status', 8)]);
            }
        }

        // ---- our changes ----
        $commands = [];
        $more = false;
        if (Wbxml::value($col, 'AirSync:GetChanges') !== '0') {
            $window = max(1, min(512, (int) (Wbxml::value($col, 'AirSync:WindowSize') ?: 100)));
            $now = $this->current($cid, $filter);
            $pending = [];
            foreach ($base as $id => $f) {
                if (!array_key_exists((string) $id, $now)) {
                    $pending[] = ['d', (string) $id];
                }
            }
            foreach ($now as $id => $f) {
                $id = (string) $id;
                if (!array_key_exists($id, $base)) {
                    $pending[] = ['a', $id];
                } elseif ((int) $base[$id] !== $f) {
                    $pending[] = ['c', $id];
                }
            }
            $more = count($pending) > $window;
            $ids = array_map(fn ($p) => (int) $p[1], array_filter(array_slice($pending, 0, $window), fn ($p) => $p[0] !== 'd'));
            $msgs = MailMessage::with('attachments')->whereIn('id', $ids ?: [0])->get()->keyBy('id');
            foreach (array_slice($pending, 0, $window) as [$kind, $id]) {
                if ($kind === 'd') {
                    $commands[] = Wbxml::el('AirSync:Delete', [Wbxml::text('AirSync:ServerId', $id)]);
                    unset($base[$id]);
                    continue;
                }
                $m = $msgs[(int) $id] ?? null;
                if (!$m) {
                    continue;
                }
                if ($kind === 'a') {
                    $commands[] = Wbxml::el('AirSync:Add', [Wbxml::text('AirSync:ServerId', $id), Wbxml::el('AirSync:ApplicationData', $this->appData($m, $options))]);
                } else {
                    $commands[] = Wbxml::el('AirSync:Change', [Wbxml::text('AirSync:ServerId', $id), Wbxml::el('AirSync:ApplicationData', [
                        Wbxml::text('Email:Read', $m->seen ? 1 : 0), $this->flag($m)])]);
                }
                $base[$id] = self::flags($m);
            }
        }

        $newKey = $key + 1;
        $state->fill(['prev_key' => $key, 'prev_items' => json_encode((object) $before), 'sync_key' => $newKey, 'items' => json_encode((object) $base), 'filter' => $filter])->save();

        return Wbxml::el('AirSync:Collection', array_merge($head(1, $newKey), [
            $more ? Wbxml::el('AirSync:MoreAvailable') : null,
            $commands ? Wbxml::el('AirSync:Commands', $commands) : null,
            $responses ? Wbxml::el('AirSync:Responses', $responses) : null,
        ]));
    }

    // ---------------- one message as ActiveSync data ----------------

    private function appData(MailMessage $m, ?array $options): array
    {
        [$type, $limit] = $this->bodyPreference($options);
        if ($type === 4) {
            $data = DriveArchive::raw($m);
        } elseif ($type === 2 && $m->body_html) {
            $data = (string) $m->body_html;
        } else {
            $type = 1;
            $data = (string) ($m->body_text ?: trim(html_entity_decode(strip_tags((string) $m->body_html), ENT_QUOTES)));
        }
        $full = strlen($data);
        $truncated = $limit > 0 && $full > $limit;
        if ($truncated) {
            $data = mb_strcut($data, 0, $limit, 'UTF-8');
        }
        $fmt = fn (array $list) => implode(', ', array_map(fn ($a) => '<' . $a . '>', array_filter((array) $list)));
        $from = $m->from_name ? '"' . str_replace('"', '', $m->from_name) . '" <' . $m->from_email . '>' : '<' . $m->from_email . '>';
        $atts = [];
        foreach ($m->attachments as $a) {
            $atts[] = Wbxml::el('AirSyncBase:Attachment', [Wbxml::text('AirSyncBase:DisplayName', $a->filename ?: 'file'), Wbxml::text('AirSyncBase:FileReference', 'a' . $a->id),
                Wbxml::text('AirSyncBase:Method', 1), Wbxml::text('AirSyncBase:EstimatedDataSize', (int) $a->size)]);
        }

        return array_values(array_filter([
            Wbxml::text('Email:To', $fmt($m->to)), $m->cc ? Wbxml::text('Email:Cc', $fmt($m->cc)) : null, Wbxml::text('Email:From', $from),
            Wbxml::text('Email:Subject', (string) $m->subject), Wbxml::text('Email:DateReceived', ($m->sent_at ?? $m->created_at)->copy()->utc()->format('Y-m-d\TH:i:s.000\Z')),
            Wbxml::text('Email:DisplayTo', implode('; ', (array) $m->to)), Wbxml::text('Email:ThreadTopic', (string) $m->subject), Wbxml::text('Email:Importance', 1),
            Wbxml::text('Email:Read', $m->seen ? 1 : 0),
            $atts ? Wbxml::el('AirSyncBase:Attachments', $atts) : null,
            Wbxml::el('AirSyncBase:Body', [Wbxml::text('AirSyncBase:Type', $type), Wbxml::text('AirSyncBase:EstimatedDataSize', $full), Wbxml::text('AirSyncBase:Truncated', $truncated ? 1 : 0),
                $data !== '' ? Wbxml::text('AirSyncBase:Data', $data) : null]),
            Wbxml::text('Email:MessageClass', 'IPM.Note'), Wbxml::text('Email:InternetCPID', 65001), $this->flag($m),
            Wbxml::text('Email:ContentClass', 'urn:content-classes:message'), Wbxml::text('AirSyncBase:NativeBodyType', $m->body_html ? 2 : 1),
        ]));
    }

    private function flag(MailMessage $m): array
    {
        return $m->starred ? Wbxml::el('Email:Flag', [Wbxml::text('Email:Status', 2), Wbxml::text('Email:FlagType', 'Flag for follow up')]) : Wbxml::el('Email:Flag');
    }

    /** [body type 1 plain / 2 HTML / 4 MIME, truncation size (0 = none)] from the phone's options */
    private function bodyPreference(?array $options): array
    {
        $prefs = [];
        foreach (Wbxml::children($options, 'AirSyncBase:BodyPreference') as $p) {
            $prefs[(int) Wbxml::value($p, 'AirSyncBase:Type')] = (int) Wbxml::value($p, 'AirSyncBase:TruncationSize');
        }
        if (Wbxml::value($options, 'AirSync:MIMESupport') === '2' && !$prefs) {
            $prefs[4] = 0;
        }
        foreach ([2, 4, 1] as $t) {
            if (array_key_exists($t, $prefs)) {
                return [$t, $prefs[$t]];
            }
        }

        return [1, 0];
    }

    // ---------------- estimate, fetch, attachments, move ----------------

    private function estimate(?array $req): array
    {
        $out = [];
        foreach (Wbxml::children(Wbxml::child($req, 'GetItemEstimate:Collections'), 'GetItemEstimate:Collection') as $col) {
            $cid = (string) (Wbxml::value($col, 'GetItemEstimate:CollectionId') ?? '');
            if (!isset(self::FOLDERS[$cid])) {
                $out[] = Wbxml::el('GetItemEstimate:Response', [Wbxml::text('GetItemEstimate:Status', 2)]);
                continue;
            }
            $state = EasState::where('device_id', $this->device->id)->where('collection', $cid)->first();
            $filter = (int) (Wbxml::value($col, 'AirSync:Options', 'AirSync:FilterType') ?? $state?->filter ?? 0);
            $out[] = Wbxml::el('GetItemEstimate:Response', [Wbxml::text('GetItemEstimate:Status', 1), Wbxml::el('GetItemEstimate:Collection', [
                Wbxml::text('GetItemEstimate:CollectionId', $cid), Wbxml::text('GetItemEstimate:Estimate', $this->changesFor($cid, $state, $filter))])]);
        }

        return Wbxml::el('GetItemEstimate:GetItemEstimate', $out);
    }

    private function changesFor(string $cid, ?EasState $state, int $filter): int
    {
        $base = $state?->itemsArray() ?? [];
        $now = $this->current($cid, $filter);
        $n = count(array_diff_key($base, $now));
        foreach ($now as $id => $f) {
            $n += !array_key_exists((string) $id, $base) || (int) $base[(string) $id] !== $f ? 1 : 0;
        }

        return $n;
    }

    private function attachment(string $ref): ?MailAttachment
    {
        if (!preg_match('/^a(\d+)$/', $ref, $m)) {
            return null;
        }
        $a = MailAttachment::find((int) $m[1]);

        return $a && $this->message((string) $a->message_id) ? $a : null; // the attachment's message must be one of this phone's
    }

    private function itemOperations(?array $req): array
    {
        $out = [];
        foreach ($req['c'] ?? [] as $op) {
            if ($op['n'] === 'ItemOperations:Fetch') {
                $ref = Wbxml::value($op, 'AirSyncBase:FileReference');
                if ($ref !== null) {
                    $a = $this->attachment($ref);
                    $out[] = $a && $a->path && Storage::disk('private')->exists($a->path)
                        ? Wbxml::el('ItemOperations:Fetch', [Wbxml::text('ItemOperations:Status', 1), Wbxml::text('AirSyncBase:FileReference', $ref), Wbxml::el('ItemOperations:Properties', [
                            Wbxml::text('AirSyncBase:ContentType', $a->mime ?: 'application/octet-stream'), Wbxml::text('ItemOperations:Data', base64_encode((string) Storage::disk('private')->get($a->path)))])])
                        : Wbxml::el('ItemOperations:Fetch', [Wbxml::text('ItemOperations:Status', 15), Wbxml::text('AirSyncBase:FileReference', $ref)]);
                    continue;
                }
                $sid = (string) Wbxml::value($op, 'AirSync:ServerId');
                $m = $this->message($sid);
                $out[] = $m
                    ? Wbxml::el('ItemOperations:Fetch', [Wbxml::text('ItemOperations:Status', 1), Wbxml::text('AirSync:CollectionId', $m->folder), Wbxml::text('AirSync:ServerId', $sid),
                        Wbxml::text('AirSync:Class', 'Email'), Wbxml::el('ItemOperations:Properties', $this->appData($m->load('attachments'), Wbxml::child($op, 'ItemOperations:Options')))])
                    : Wbxml::el('ItemOperations:Fetch', [Wbxml::text('ItemOperations:Status', 6), Wbxml::text('AirSync:ServerId', $sid)]);
            } elseif ($op['n'] === 'ItemOperations:EmptyFolderContents') {
                $cid = (string) Wbxml::value($op, 'AirSync:CollectionId');
                if ($cid === 'trash' || $cid === 'spam') {
                    foreach ($this->query($cid)->limit(5000)->get() as $m) {
                        Mailbox::purge($m);
                    }
                }
                $out[] = Wbxml::el('ItemOperations:EmptyFolderContents', [Wbxml::text('ItemOperations:Status', in_array($cid, ['trash', 'spam'], true) ? 1 : 3), Wbxml::text('AirSync:CollectionId', $cid)]);
            }
        }

        return Wbxml::el('ItemOperations:ItemOperations', [Wbxml::text('ItemOperations:Status', 1), Wbxml::el('ItemOperations:Response', $out)]);
    }

    private function getAttachment(string $name): array
    {
        $a = $this->attachment($name);
        if (!$a || !$a->path || !Storage::disk('private')->exists($a->path)) {
            return [404, '', 'text/plain'];
        }

        return [200, (string) Storage::disk('private')->get($a->path), $a->mime ?: 'application/octet-stream'];
    }

    private function moveItems(?array $req): array
    {
        $out = [];
        foreach (Wbxml::children($req, 'Move:Move') as $mv) {
            $sid = (string) Wbxml::value($mv, 'Move:SrcMsgId');
            $dst = (string) Wbxml::value($mv, 'Move:DstFldId');
            $m = $this->message($sid);
            $status = !$m ? 1 : (!isset(self::FOLDERS[$dst]) || in_array($dst, ['sent', 'drafts'], true) ? 2 : ($m->folder === $dst ? 4 : 3));
            if ($status === 3) {
                $m->forceFill(['folder' => $dst])->save();
            }
            $out[] = Wbxml::el('Move:Response', [Wbxml::text('Move:SrcMsgId', $sid), Wbxml::text('Move:Status', $status), $status === 3 ? Wbxml::text('Move:DstMsgId', $sid) : null]);
        }

        return Wbxml::el('Move:MoveItems', $out);
    }

    // ---------------- sending ----------------

    private function send(string $cmd, ?array $req, string $body, array $query): array
    {
        $page = 'ComposeMail:' . $cmd;
        if ($req) {
            $mime = (string) Wbxml::value($req, 'ComposeMail:Mime');
            $itemId = Wbxml::value($req, 'ComposeMail:Source', 'ComposeMail:ItemId');
            $replace = Wbxml::child($req, 'ComposeMail:ReplaceMime') !== null;
        } else { // 12.x: raw MIME body, the original in the query
            $mime = $body;
            $itemId = $query['ItemId'] ?? null;
            $replace = false;
        }
        $fail = fn (int $status) => $req ? Wbxml::el($page, [Wbxml::text('ComposeMail:Status', $status)]) : [500, '', 'text/plain'];
        $account = $this->account ?? MailAccount::where('user_id', $this->user->id)->orderByDesc('is_default')->orderBy('id')->first();
        if (!$account || trim($mime) === '') {
            return $fail(102);
        }
        $p = (new MimeParser())->parse($mime);
        $h = $p['headers'];
        $to = MimeParser::addresses(implode(',', $h['to'] ?? []));
        $cc = MimeParser::addresses(implode(',', $h['cc'] ?? []));
        $bcc = MimeParser::addresses(implode(',', $h['bcc'] ?? []));
        if (!$to && !$cc && !$bcc) {
            return $fail(102);
        }
        $html = $p['html'] !== '' ? $p['html'] : nl2br(e($p['text']));
        $attachments = $p['attachments'];
        $original = $itemId !== null && $cmd !== 'SendMail' ? $this->message((string) $itemId) : null;
        if ($original && !$replace) {
            $quote = $original->body_html ?: nl2br(e((string) $original->body_text));
            $html .= '<br><br><div>' . e(($original->sent_at ?? $original->created_at)->format('d M Y H:i')) . ', ' . e($original->from_name ?: $original->from_email) . ':</div><blockquote style="margin:0 0 0 8px;border-left:2px solid #ccc;padding-left:8px">' . $quote . '</blockquote>';
            if ($cmd === 'SmartForward') {
                foreach ($original->attachments as $a) {
                    if ($a->path && Storage::disk('private')->exists($a->path)) {
                        $attachments[] = ['filename' => $a->filename, 'mime' => $a->mime, 'content' => (string) Storage::disk('private')->get($a->path)];
                    }
                }
            }
        }
        try {
            Mailbox::send($account, $to, $cc, $bcc, mb_substr(MimeParser::decodeHeader(MimeParser::header($h, 'subject')), 0, 300), $html, $attachments, $cmd === 'SmartReply' ? $original : null);
        } catch (Throwable $e) {
            report($e);

            return $fail(120);
        }

        return [200, '', 'application/vnd.ms-sync.wbxml'];
    }

    // ---------------- settings, provisioning, push ----------------

    private function settings(?array $req): array
    {
        $out = [Wbxml::text('Settings:Status', 1)];
        if (Wbxml::child($req, 'Settings:DeviceInformation')) {
            $out[] = Wbxml::el('Settings:DeviceInformation', [Wbxml::text('Settings:Status', 1)]);
        }
        if (Wbxml::child($req, 'Settings:UserInformation')) {
            $email = $this->account?->email ?? (string) $this->user->email;
            $get = $this->version >= '14.1'
                ? Wbxml::el('Settings:Get', [Wbxml::el('Settings:Accounts', [Wbxml::el('Settings:Account', [Wbxml::text('Settings:UserDisplayName', (string) $this->user->name),
                    Wbxml::el('Settings:EmailAddresses', [Wbxml::text('Settings:PrimarySmtpAddress', $email)])])])])
                : Wbxml::el('Settings:Get', [Wbxml::el('Settings:EmailAddresses', [Wbxml::text('Settings:SmtpAddress', $email)])]);
            $out[] = Wbxml::el('Settings:UserInformation', [Wbxml::text('Settings:Status', 1), $get]);
        }
        if (Wbxml::child($req, 'Settings:Oof')) {
            $out[] = Wbxml::el('Settings:Oof', [Wbxml::text('Settings:Status', 1), Wbxml::el('Settings:Get', [Wbxml::text('Settings:OofState', 0)])]);
        }

        return Wbxml::el('Settings:Settings', $out);
    }

    /** No device rules are enforced (no PIN, no wipe): the phone gets an empty policy and a key. */
    private function provision(?array $req): array
    {
        $policy = Wbxml::child(Wbxml::child($req, 'Provision:Policies'), 'Provision:Policy');
        $out = [Wbxml::text('Provision:Status', 1)];
        if (Wbxml::child($req, 'Settings:DeviceInformation')) {
            $out[] = Wbxml::el('Settings:DeviceInformation', [Wbxml::text('Settings:Status', 1)]);
        }
        if (!$policy) {
            return Wbxml::el('Provision:Provision', $out);
        }
        $type = (string) (Wbxml::value($policy, 'Provision:PolicyType') ?: 'MS-EAS-Provisioning-WBXML');
        if (Wbxml::value($policy, 'Provision:PolicyKey') === null) { // step 1: here is the policy, with a temporary key
            $doc = Wbxml::el('Provision:EASProvisionDoc', [Wbxml::text('Provision:DevicePasswordEnabled', 0), Wbxml::text('Provision:AlphanumericDevicePasswordRequired', 0),
                Wbxml::text('Provision:PasswordRecoveryEnabled', 0), Wbxml::text('Provision:RequireDeviceEncryption', 0), Wbxml::text('Provision:AttachmentsEnabled', 1),
                Wbxml::text('Provision:AllowSimpleDevicePassword', 1), Wbxml::text('Provision:AllowHTMLEmail', 1)]);
            $out[] = Wbxml::el('Provision:Policies', [Wbxml::el('Provision:Policy', [Wbxml::text('Provision:PolicyType', $type), Wbxml::text('Provision:Status', 1),
                Wbxml::text('Provision:PolicyKey', random_int(100000000, 999999999)), Wbxml::el('Provision:Data', [$doc])])]);
        } else { // step 2: the phone accepted it, here is the final key
            $key = random_int(100000000, 999999999);
            $this->device->forceFill(['policy_key' => $key])->save();
            $out[] = Wbxml::el('Provision:Policies', [Wbxml::el('Provision:Policy', [Wbxml::text('Provision:PolicyType', $type), Wbxml::text('Provision:Status', 1),
                Wbxml::text('Provision:PolicyKey', $key)])]);
        }

        return Wbxml::el('Provision:Provision', $out);
    }

    /**
     * Push: the phone waits for new mail. On shared hosting every waiting phone keeps one PHP worker busy, so we wait at most
     * mailbox.eas_ping_seconds (default 20) and then answer "nothing new"; the phone asks again.
     */
    private function ping(?array $req): array
    {
        $folders = [];
        foreach (Wbxml::children(Wbxml::child($req, 'Ping:Folders'), 'Ping:Folder') as $f) {
            $id = (string) Wbxml::value($f, 'Ping:Id');
            if (isset(self::FOLDERS[$id])) {
                $folders[] = $id;
            }
        }
        $folders = $folders ?: ['inbox'];
        $until = microtime(true) + max(0, (int) config('mailbox.eas_ping_seconds', 20));
        do {
            $changed = [];
            foreach ($folders as $id) {
                $state = EasState::where('device_id', $this->device->id)->where('collection', $id)->first();
                if ($state && $this->changesFor($id, $state, (int) $state->filter) > 0) {
                    $changed[] = $id;
                }
            }
            if ($changed) {
                return Wbxml::el('Ping:Ping', [Wbxml::text('Ping:Status', 2), Wbxml::el('Ping:Folders', array_map(fn ($id) => Wbxml::text('Ping:Folder', $id), $changed))]);
            }
            if (microtime(true) >= $until) {
                break;
            }
            sleep(5);
        } while (true);

        return Wbxml::el('Ping:Ping', [Wbxml::text('Ping:Status', 1)]);
    }
}
