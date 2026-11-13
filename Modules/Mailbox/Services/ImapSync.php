<?php

namespace Modules\Mailbox\Services;

use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Models\MailMessage;
use Modules\Mailbox\Models\MailSetting;

/** Pulls new mail of an external account (IMAP / Gmail) into Meet: Inbox and Sent, newest first on the first run. */
class ImapSync
{
    public static function open(MailAccount $a): ImapClient
    {
        if ($a->kind === 'google') {
            $imap = (new ImapClient('imap.gmail.com', 993, 'ssl'))->connect();
            $imap->oauth($a->email, GoogleApi::accessToken(MailSetting::for($a->user_id)));

            return $imap;
        }
        $imap = (new ImapClient(Mailbox::host((string) $a->imap_host), (int) $a->imap_port, (string) $a->imap_security))->connect();
        $imap->login((string) ($a->username ?: $a->email), (string) $a->secret);

        return $imap;
    }

    /** @return int new messages */
    public static function run(MailAccount $a): int
    {
        $new = 0;
        $state = $a->sync_state ?? [];
        try {
            $imap = self::open($a);
            $folders = ['inbox' => 'INBOX'];
            if ($sent = $imap->special('Sent', ['sent', 'sent items', 'sent mail', 'inbox.sent'])) {
                $folders['sent'] = $sent;
            }
            $max = (int) config('mailbox.max_message_mb', 20) * 1048576;
            foreach ($folders as $local => $remote) {
                $info = $imap->select($remote);
                $st = $state[$remote] ?? ['uidvalidity' => 0, 'last' => 0];
                if ((int) $st['uidvalidity'] !== $info['uidvalidity']) {
                    $st = ['uidvalidity' => $info['uidvalidity'], 'last' => 0]; // the server renumbered: start again
                }
                if ($info['exists'] === 0) {
                    $state[$remote] = $st;
                    continue;
                }
                $uids = $st['last'] > 0 ? $imap->search('UID ' . ($st['last'] + 1) . ':*') : $imap->search('ALL');
                $uids = array_values(array_filter($uids, fn ($u) => $u > $st['last']));
                if ($st['last'] === 0) {
                    $uids = array_slice($uids, -(int) config('mailbox.sync_initial', 200));
                }
                foreach (array_chunk($uids, 50) as $chunk) {
                    $info2 = $imap->sizes($chunk);
                    foreach ($chunk as $uid) {
                        $st['last'] = max($st['last'], $uid);
                        if (($info2[$uid]['size'] ?? 0) > $max || MailMessage::where('account_id', $a->id)->where('remote_folder', $remote)->where('remote_uid', $uid)->exists()) {
                            continue;
                        }
                        $raw = $imap->fetch($uid);
                        if ($raw === null) {
                            continue;
                        }
                        $msg = Mailbox::ingest($a, $raw, $local, ['remote_folder' => $remote, 'remote_uid' => $uid, 'seen' => $local !== 'inbox' || ($info2[$uid]['seen'] ?? false)]);
                        $new += $msg ? 1 : 0;
                    }
                }
                $state[$remote] = $st;
            }
            $imap->logout();
            $a->forceFill(['sync_state' => $state, 'last_sync_at' => now(), 'last_error' => null])->save();
        } catch (\Throwable $e) {
            $a->forceFill(['sync_state' => $state, 'last_error' => mb_substr($e->getMessage(), 0, 300)])->save();
        }

        return $new;
    }

    /** Mirror read / unread and delete to the provider (best effort). */
    public static function pushFlag(MailMessage $m, string $flag, bool $on): void
    {
        if (!$m->remote_uid || !in_array($m->account->kind, ['imap', 'google'], true)) {
            return;
        }
        try {
            $imap = self::open($m->account);
            $imap->select((string) $m->remote_folder);
            $imap->flag((int) $m->remote_uid, $flag, $on);
            $imap->logout();
        } catch (\Throwable) {
            // shown again at the next sync
        }
    }
}
