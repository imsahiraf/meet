<?php

namespace Modules\Mailbox\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Models\MailAttachment;
use Modules\Mailbox\Models\MailContact;
use Modules\Mailbox\Models\MailFilter;
use Modules\Mailbox\Models\MailLabel;
use Modules\Mailbox\Models\MailMessage;
use Modules\Mailbox\Services\DriveArchive;
use Modules\Mailbox\Services\ImapSync;
use Modules\Mailbox\Services\Mailbox;

class MailController extends Controller
{
    /**
     * Whose mailbox these screens show: mine, or one a colleague shared with me (Mail settings > Share my mailbox) after I
     * opened it from the menu (?as=<owner>, ?as=0 back to mine). Checked on every request, so taking the share away works at once.
     */
    private function uid(): int
    {
        $me = (int) Auth::id();
        if (request()->has('as')) {
            $as = (int) request()->query('as');
            $as && $as !== $me && \Illuminate\Support\Facades\DB::table('mail_delegates')->where('owner_id', $as)->where('delegate_id', $me)->exists()
                ? session(['mail_as' => $as]) : session()->forget('mail_as');
        }
        $as = (int) session('mail_as', 0);
        if ($as && \Illuminate\Support\Facades\DB::table('mail_delegates')->where('owner_id', $as)->where('delegate_id', $me)->exists()) {
            return $as;
        }
        session()->forget('mail_as');

        return $me;
    }

    private function mine()
    {
        return MailMessage::where('user_id', $this->uid());
    }

    private function message(int $id): MailMessage
    {
        return $this->mine()->with(['attachments', 'account', 'labels'])->findOrFail($id);
    }

    private function common(): array
    {
        $uid = $this->uid();
        $unread = MailMessage::where('user_id', $uid)->where('seen', false)->whereIn('folder', ['inbox', 'spam'])->selectRaw('folder, count(*) c')->groupBy('folder')->pluck('c', 'folder');

        return [
            'accounts' => MailAccount::where('user_id', $uid)->orderByDesc('is_default')->get(),
            'labels' => MailLabel::where('user_id', $uid)->orderBy('name')->get(),
            'unread' => $unread, 'quota' => Mailbox::quota($uid),
            'drafts' => MailMessage::where('user_id', $uid)->where('folder', 'drafts')->count(),
            'actingAs' => $uid !== (int) Auth::id() ? \Modules\Authentication\Models\User::find($uid) : null,
            'sharedWithMe' => \Modules\Authentication\Models\User::whereIn('id', \Illuminate\Support\Facades\DB::table('mail_delegates')->where('delegate_id', Auth::id())->pluck('owner_id'))->get(['id', 'full_name']),
        ];
    }

    public function index(Request $request)
    {
        $folder = array_key_exists($request->query('folder'), MailMessage::FOLDERS) ? $request->query('folder') : 'inbox';
        $q = $this->mine()->with(['labels', 'account']);
        // search words (from: has:attachment is:unread in:anywhere ...); in: picks the folder
        if (($s = trim((string) $request->query('q'))) !== '' && ($in = \Modules\Mailbox\Services\SearchWords::apply($q, $s, $this->uid()))) {
            $folder = $in === 'anywhere' || array_key_exists($in, MailMessage::FOLDERS) ? $in : $folder;
        }
        $label = (int) $request->query('label');
        if ($folder === 'anywhere') {
            $q->whereNotIn('folder', ['trash', 'spam']);
        } elseif ($label) {
            $q->whereHas('labels', fn ($w) => $w->where('mail_labels.id', $label));
        } elseif ($folder === 'starred') {
            $q->where('starred', true)->where('folder', '!=', 'trash');
        } else {
            $q->where('folder', $folder);
        }
        if ($acc = (int) $request->query('account')) {
            $q->where('account_id', $acc);
        }
        if ($request->boolean('unread')) {
            $q->where('seen', false);
        }
        // conversation view (setting, on by default): one line per conversation - its newest message - with how many it has
        $threads = (bool) (\Modules\Mailbox\Models\MailSetting::for($this->uid())->conversations ?? true) && !in_array($folder, ['drafts', 'scheduled'], true);
        if ($threads) {
            $q->whereIn('id', (clone $q)->reorder()->selectRaw('max(mail_messages.id)')->groupByRaw('coalesce(thread_key, cast(mail_messages.id as char))'));
        }
        $messages = $q->orderByDesc('sent_at')->paginate(40)->withQueryString();
        $counts = $threads ? $this->mine()->whereIn('thread_key', $messages->pluck('thread_key')->filter()->unique()->values())->where('folder', '!=', 'trash')
            ->selectRaw('thread_key, count(*) n')->groupBy('thread_key')->pluck('n', 'thread_key') : collect();

        return view('mailbox::index', $this->common() + ['messages' => $messages, 'folder' => $folder, 'search' => $s ?? '', 'labelId' => $label, 'counts' => $counts]);
    }

    public function show(Request $request, int $id)
    {
        $m = $this->message($id);
        if (!$m->seen) {
            $m->forceFill(['seen' => true])->save();
            ImapSync::pushFlag($m, '\\Seen', true);
        }
        $body = ['text' => $m->body_text, 'html' => $m->body_html, 'attachments' => null];
        $archiveError = null;
        if ($m->drive_file_id) {
            try {
                $p = DriveArchive::load($m);
                $body = ['text' => $p['text'], 'html' => $p['html'], 'attachments' => $p['attachments']];
            } catch (\Throwable $e) {
                $archiveError = $e->getMessage();
            }
        }
        $thread = $m->thread_key ? $this->mine()->where('thread_key', $m->thread_key)->where('id', '!=', $m->id)->where('folder', '!=', 'trash')
            ->orderBy('sent_at')->get(['id', 'from_name', 'from_email', 'snippet', 'sent_at', 'folder']) : collect();

        // calendar invites in the mail: their events as a card (the .ics stays a normal attachment to download)
        $events = [];
        foreach ($m->attachments as $a) {
            if ($a->path && (str_contains((string) $a->mime, 'calendar') || str_ends_with(strtolower($a->filename), '.ics')) && Storage::disk('local')->exists($a->path) && $a->size < 512000) {
                $events = array_merge($events, \Modules\Mailbox\Services\Ics::events((string) Storage::disk('local')->get($a->path)));
            }
        }

        return view('mailbox::show', $this->common() + ['m' => $m, 'body' => $body, 'thread' => $thread, 'archiveError' => $archiveError, 'events' => $events,
            'images' => $request->boolean('images'), 'folder' => $m->folder]);
    }

    public function act(Request $request, int $id)
    {
        $m = $this->message($id);
        $this->apply($m, (string) $request->input('action'), (string) $request->input('value'));

        if ($request->input('action') === 'cancel') {
            return redirect()->route('mail.compose', ['draft' => $m->id])->with('status', __('Not sent. It is a draft again.'));
        }

        return $request->input('back') === 'list' || in_array($request->input('action'), ['delete', 'move', 'spam', 'archive', 'snooze'], true)
            ? redirect()->route('mail.index', ['folder' => $request->input('from', 'inbox')])->with('status', __('Done.'))
            : back()->with('status', __('Done.'));
    }

    public function bulk(Request $request)
    {
        $ids = array_map('intval', (array) $request->input('ids', []));
        foreach ($this->mine()->whereIn('id', array_slice($ids, 0, 200))->get() as $m) {
            $this->apply($m, (string) $request->input('action'), (string) $request->input('value'));
        }

        return back()->with('status', __(':n messages updated.', ['n' => count($ids)]));
    }

    private function apply(MailMessage $m, string $action, string $value): void
    {
        switch ($action) {
            case 'read': case 'unread':
                $m->seen = $action === 'read';
                ImapSync::pushFlag($m, '\\Seen', $m->seen);
                break;
            case 'star': case 'unstar':
                $m->starred = $action === 'star';
                ImapSync::pushFlag($m, '\\Flagged', $m->starred);
                break;
            case 'archive': case 'spam': case 'inbox':
                // spam training: "Spam" sends this sender's next mail to Spam too, "Not spam" forgets that again
                if ($action === 'spam' && $m->from_email && $m->folder !== 'spam') {
                    MailFilter::firstOrCreate(['user_id' => $m->user_id, 'field' => 'from', 'contains' => strtolower($m->from_email), 'action' => 'folder', 'value' => 'spam']);
                } elseif ($action === 'inbox' && $m->folder === 'spam' && $m->from_email) {
                    MailFilter::where('user_id', $m->user_id)->where('field', 'from')->where('contains', strtolower($m->from_email))->where('action', 'folder')->where('value', 'spam')->delete();
                }
                $m->folder = $action;
                break;
            case 'move':
                if (in_array($value, ['inbox', 'archive', 'spam', 'trash'], true)) {
                    $m->folder = $value;
                }
                break;
            case 'label':
                if ($label = MailLabel::where('user_id', $m->user_id)->find((int) $value)) {
                    $m->labels()->syncWithoutDetaching([$label->id]);
                }
                break;
            case 'snooze': // back in the inbox later: a few hours, tomorrow morning, next week or a picked time
                $until = match ($value) {
                    'later' => now()->addHours(3), 'tomorrow' => now()->addDay()->setTime(9, 0), 'week' => now()->next(\Carbon\CarbonInterface::MONDAY)->setTime(9, 0),
                    default => strtotime($value) && strtotime($value) > time() ? \Illuminate\Support\Carbon::parse($value) : null,
                };
                if ($until && in_array($m->folder, ['inbox', 'archive'], true)) {
                    $m->folder = 'snoozed';
                    $m->snooze_until = $until;
                }
                break;
            case 'unsnooze':
                if ($m->folder === 'snoozed') {
                    $m->folder = 'inbox';
                    $m->snooze_until = null;
                }
                break;
            case 'cancel': // a scheduled message (or one in its undo wait) becomes a draft again
                if ($m->folder === 'scheduled') {
                    \Modules\Mailbox\Services\Outbox::cancel($m);
                }

                return;
            case 'unlabel':
                $m->labels()->detach((int) $value);
                break;
            case 'delete':
                if ($m->folder === 'trash' || $m->folder === 'drafts') {
                    Mailbox::purge($m);

                    return;
                }
                $m->folder = 'trash';
                break;
        }
        $m->save();
    }

    public function emptyTrash()
    {
        $n = 0;
        foreach ($this->mine()->where('folder', 'trash')->with('attachments')->get() as $m) {
            Mailbox::purge($m);
            $n++;
        }

        return redirect()->route('mail.index', ['folder' => 'trash'])->with('status', __(':n messages deleted for good.', ['n' => $n]));
    }

    public function attachment(Request $request, int $id)
    {
        $a = MailAttachment::whereHas('message', fn ($q) => $q->where('user_id', $this->uid()))->findOrFail($id);
        abort_unless($a->path && Storage::disk('local')->exists($a->path), 404);
        $inline = $request->boolean('view') && preg_match('#^(image/(png|jpe?g|gif|webp)|application/pdf)$#', (string) $a->mime);

        return Storage::disk('local')->response($a->path, $a->filename, ['Content-Type' => $a->mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'"],
            $inline ? 'inline' : 'attachment');
    }

    public function compose(Request $request)
    {
        $common = $this->common();
        if ($common['accounts']->isEmpty()) {
            return redirect()->route('mail.settings')->with('status', __('Create your Meet address or add an e-mail account first.'));
        }
        $draft = null;
        $prefill = ['to' => (string) $request->query('to'), 'cc' => '', 'subject' => '', 'body' => '', 'account_id' => $common['accounts']->first()->id, 'reply_id' => null];
        if ($rid = (int) ($request->query('reply') ?: $request->query('forward'))) {
            $orig = $this->message($rid);
            $prefill['account_id'] = $orig->account_id;
            $quote = '<br><br><div>' . e(__('On :d, :n wrote:', ['d' => $orig->sent_at?->format('d M Y H:i'), 'n' => $orig->from_name ?: $orig->from_email])) . '</div><blockquote style="margin:0 0 0 .8ex;border-left:2px solid #ccc;padding-left:1ex">'
                . ($orig->body_html ? $this->cleanHtml(preg_replace('#<img[^>]*>#i', '', $orig->body_html)) : nl2br(e((string) $orig->body_text))) . '</blockquote>';
            if ($request->query('reply')) {
                $prefill['to'] = $orig->folder === 'sent' ? implode(', ', $orig->to ?? []) : (string) $orig->from_email;
                if ($request->boolean('all')) {
                    $mine = $orig->account->email;
                    $prefill['cc'] = implode(', ', array_diff(array_merge($orig->to ?? [], $orig->cc ?? []), [$mine, $orig->from_email]));
                }
                $prefill['subject'] = preg_match('/^re:/i', (string) $orig->subject) ? $orig->subject : 'Re: ' . $orig->subject;
                $prefill['reply_id'] = $orig->id;
            } else {
                $prefill['subject'] = preg_match('/^fwd?:/i', (string) $orig->subject) ? $orig->subject : 'Fwd: ' . $orig->subject;
                $prefill['forward_id'] = $orig->id;
            }
            $prefill['body'] = $quote;
        }
        if ($did = (int) $request->query('draft')) {
            $draft = $this->mine()->where('folder', 'drafts')->findOrFail($did);
            $prefill = ['to' => implode(', ', $draft->to ?? []), 'cc' => implode(', ', $draft->cc ?? []), 'subject' => $draft->subject, 'body' => $draft->body_html,
                'account_id' => $draft->account_id, 'reply_id' => null];
        }
        $signature = \Modules\Mailbox\Models\MailSetting::for($this->uid())->signature;

        return view('mailbox::compose', $common + ['prefill' => $prefill, 'draft' => $draft, 'signature' => $signature, 'folder' => 'compose',
            'groups' => \Modules\Mailbox\Models\MailContactGroup::where('user_id', $this->uid())->orderBy('name')->get(['name', 'emails'])]);
    }

    /** "Help me write": the text of the compose window, corrected / polite / shorter / formal (AI, same key as meeting notes). */
    public function writeHelp(Request $request)
    {
        $in = $request->validate(['mode' => 'required|in:' . implode(',', array_keys(\Modules\Mailbox\Services\MailWriter::MODES)), 'html' => 'required|string|max:60000']);
        try {
            return response()->json(['html' => \Modules\Mailbox\Services\MailWriter::rewrite($in['mode'], $in['html'])]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function send(Request $request)
    {
        $data = $request->validate([
            'account_id' => 'required|integer', 'to' => 'nullable|string|max:4000', 'cc' => 'nullable|string|max:4000', 'bcc' => 'nullable|string|max:4000',
            'subject' => 'nullable|string|max:300', 'body' => 'nullable|string|max:2000000', 'files.*' => 'file|max:' . ((int) config('mailbox.max_message_mb', 20) * 1024),
            'reply_id' => 'nullable|integer', 'forward_id' => 'nullable|integer', 'draft_id' => 'nullable|integer', 'save' => 'nullable|string',
            'send_at' => 'nullable|date|after:now|before:+1 year', 'receipt' => 'nullable|boolean',
        ]);
        $receipt = !empty($data['receipt']);
        $account = MailAccount::where('user_id', $this->uid())->findOrFail($data['account_id']);
        // "Name <a@b.c>, x@y.z" -> addresses
        $split = fn ($v) => array_values(array_unique(array_filter(array_map(function ($x) {
            $x = trim($x);

            return strtolower(trim(preg_match('/<([^>]+)>/', $x, $m) ? $m[1] : $x, " \t\n<>\"'"));
        }, preg_split('/[,;]+/', (string) $v)), fn ($x) => $x !== '')));
        [$to, $cc, $bcc] = [$split($data['to'] ?? ''), $split($data['cc'] ?? ''), $split($data['bcc'] ?? '')];
        foreach (array_merge($to, $cc, $bcc) as $addr) {
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['to' => __('":a" is not an e-mail address.', ['a' => $addr])]);
            }
        }
        $html = $this->cleanHtml((string) ($data['body'] ?? ''));
        $files = [];
        foreach ($request->file('files', []) as $f) {
            $files[] = ['filename' => $f->getClientOriginalName(), 'mime' => $f->getMimeType(), 'content' => file_get_contents($f->getRealPath())];
        }
        if ($fid = (int) ($data['forward_id'] ?? 0)) { // forward keeps the original attachments
            foreach ($this->message($fid)->attachments as $a) {
                if ($a->path && Storage::disk('local')->exists($a->path)) {
                    $files[] = ['filename' => $a->filename, 'mime' => $a->mime, 'content' => Storage::disk('local')->get($a->path)];
                }
            }
        }
        $draft = !empty($data['draft_id']) ? $this->mine()->where('folder', 'drafts')->find($data['draft_id']) : null;

        if ($request->input('save') === 'draft') {
            $draft ??= new MailMessage(['user_id' => $this->uid(), 'folder' => 'drafts', 'status' => 'draft']);
            $draft->fill(['account_id' => $account->id, 'to' => $to, 'cc' => $cc, 'bcc' => $bcc, 'subject' => $data['subject'] ?? '', 'body_html' => $html,
                'snippet' => mb_substr(strip_tags($html), 0, 200), 'from_email' => $account->email, 'seen' => true, 'sent_at' => now()])->save();

            return redirect()->route('mail.compose', ['draft' => $draft->id])->with('status', __('Draft saved.'));
        }
        if (!$to && !$cc && !$bcc) {
            throw ValidationException::withMessages(['to' => __('Add at least one recipient.')]);
        }
        $total = array_sum(array_map(fn ($f) => strlen($f['content']), $files));
        if ($total > (int) config('mailbox.max_message_mb', 20) * 1048576) {
            throw ValidationException::withMessages(['files' => __('Attachments are larger than :n MB.', ['n' => config('mailbox.max_message_mb', 20)])]);
        }
        $reply = !empty($data['reply_id']) ? $this->message((int) $data['reply_id']) : null;
        // send later (a time picked in the form) or the person's "undo send" wait: the message waits in Scheduled
        $undo = (int) (\Modules\Mailbox\Models\MailSetting::for($this->uid())->undo_seconds ?? 0);
        if (!empty($data['send_at']) || $undo > 0) {
            $at = !empty($data['send_at']) ? \Illuminate\Support\Carbon::parse($data['send_at']) : now()->addSeconds($undo);
            $queued = \Modules\Mailbox\Services\Outbox::queue($account, $to, $cc, $bcc, (string) ($data['subject'] ?? ''), $html, $files, $reply, $at);
            if ($receipt) {
                $queued->forceFill(['send_meta' => ['receipt_wanted' => true]])->save();
            }
            if ($draft) {
                Mailbox::purge($draft);
            }

            return !empty($data['send_at'])
                ? redirect()->route('mail.index', ['folder' => 'scheduled'])->with('status', __('Scheduled for :t.', ['t' => $at->format('d M Y, H:i')]))
                : redirect()->route('mail.index')->with('status', __('Sending ...'))->with('undo', $queued->id);
        }
        try {
            Mailbox::send($account, $to, $cc, $bcc, (string) ($data['subject'] ?? ''), $html, $files, $reply, $receipt);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['to' => __('Could not send: :e', ['e' => mb_substr($e->getMessage(), 0, 200)])]);
        }
        if ($draft) {
            Mailbox::purge($draft);
        }

        return redirect()->route('mail.index', ['folder' => 'sent'])->with('status', __('Sent.'));
    }

    /** Answer a calendar invite: the organizer gets a standard reply (their calendar marks you as going / maybe / not going). */
    public function rsvp(Request $request, int $id)
    {
        $m = $this->message($id);
        $data = $request->validate(['answer' => 'required|in:accept,tentative,decline', 'event' => 'nullable|integer|min:0|max:4']);
        $events = [];
        foreach ($m->attachments as $a) {
            if ($a->path && (str_contains((string) $a->mime, 'calendar') || str_ends_with(strtolower($a->filename), '.ics')) && Storage::disk('local')->exists($a->path)) {
                $events = array_merge($events, \Modules\Mailbox\Services\Ics::events((string) Storage::disk('local')->get($a->path)));
            }
        }
        $ev = $events[(int) ($data['event'] ?? 0)] ?? null;
        abort_unless($ev && $ev['uid'] !== '' && filter_var($ev['organizer'], FILTER_VALIDATE_EMAIL) && $ev['method'] !== 'CANCEL', 422, 'This invite cannot be answered.');
        $account = MailAccount::where('user_id', $this->uid())->findOrFail($m->account_id);
        $word = ['accept' => __('Accepted'), 'tentative' => __('Maybe'), 'decline' => __('Declined')][$data['answer']];
        $ics = \Modules\Mailbox\Services\Ics::reply($ev, $account->email, (string) ($account->display_name ?: $account->email), $data['answer']);
        try {
            Mailbox::send($account, [$ev['organizer']], [], [], $word . ': ' . $ev['title'], '<p>' . e($word . ': ' . $ev['title']) . '</p>',
                [['filename' => 'reply.ics', 'mime' => 'text/calendar; method=REPLY', 'content' => $ics]], $m);
        } catch (\Throwable $e) {
            return back()->withErrors(['rsvp' => __('Could not send: :e', ['e' => mb_substr($e->getMessage(), 0, 200)])]);
        }

        return back()->with('status', __(':w - the organizer has your answer.', ['w' => $word]));
    }

    /** Address suggestions for the compose form (own address book + Google contacts). */
    public function contacts(Request $request)
    {
        $q = '%' . str_replace(['%', '_'], ['\%', '\_'], trim((string) $request->query('q'))) . '%';

        return response()->json(MailContact::where('user_id', $this->uid())->where(fn ($w) => $w->where('email', 'like', $q)->orWhere('name', 'like', $q))
            ->orderBy('name')->limit(10)->get(['name', 'email']));
    }

    /** Compose HTML: keep simple formatting only (allow-list: no scripts, styles, frames, event handlers or unsafe links). */
    private function cleanHtml(string $html): string
    {
        return \Modules\Mailbox\Services\HtmlSanitizer::clean($html);
    }
}
