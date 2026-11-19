<?php

namespace Modules\Mailbox\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Models\MailAttachment;
use Modules\Mailbox\Models\MailContact;
use Modules\Mailbox\Models\MailFilter;
use Modules\Mailbox\Models\MailLabel;
use Modules\Mailbox\Models\MailMessage;
use Modules\Mailbox\Models\MailSetting;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** Storing incoming / sent mail, quotas, filters, the vacation reply and sending through the right account. */
class Mailbox
{
    // ---------------- storage and quota ----------------

    /** Bytes the user's own mailbox uses on this server (mail moved to Google Drive no longer counts). */
    public static function used(int $userId): int
    {
        return (int) MailMessage::where('user_id', $userId)->whereNull('drive_file_id')
            ->whereHas('account', fn ($q) => $q->where('kind', 'local'))->sum('size');
    }

    /** @return array{used: int, quota: int, pct: int, google: bool} */
    public static function quota(int $userId): array
    {
        $s = MailSetting::for($userId);
        $used = self::used($userId);
        $quota = $s->quotaBytes();

        return ['used' => $used, 'quota' => $quota, 'pct' => $quota ? (int) min(100, round($used * 100 / $quota)) : 0, 'google' => !empty($s->google_token)];
    }

    /**
     * Store one raw message in an account folder. Returns null when it is a duplicate or the mailbox is full
     * ($why tells which). Own mailboxes with Google connected may go over the size: the hourly Drive move frees space.
     */
    public static function ingest(MailAccount $account, string $raw, string $folder = 'inbox', array $extra = [], ?string &$why = null): ?MailMessage
    {
        $p = (new MimeParser())->parse($raw);
        $h = $p['headers'];
        if ($folder !== 'sent') {
            self::noteReport($account, $raw, $h);
        }
        $messageId = trim(MimeParser::header($h, 'message-id'), '<> ') ?: null;
        if ($messageId && MailMessage::where('account_id', $account->id)->where('folder', $folder)->where('message_id', $messageId)->exists()) {
            $why = 'duplicate';

            return null;
        }
        $size = strlen($raw);
        if ($account->kind === 'local' && $folder === 'inbox') {
            $q = self::quota($account->user_id);
            if ($q['used'] + $size > $q['quota'] * ($q['google'] ? 3 : 1)) {
                $why = 'mailbox full';

                return null;
            }
        }
        [$fromEmail, $fromName] = MimeParser::address(MimeParser::header($h, 'from'));
        $to = MimeParser::addresses(implode(',', $h['to'] ?? []));
        $cc = MimeParser::addresses(implode(',', $h['cc'] ?? []));
        $subject = mb_substr(MimeParser::decodeHeader(MimeParser::header($h, 'subject')), 0, 300);
        $text = $p['text'] !== '' ? $p['text'] : trim(html_entity_decode(strip_tags(preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $p['html'])), ENT_QUOTES));
        $date = strtotime(MimeParser::header($h, 'date')) ?: time();

        return DB::transaction(function () use ($account, $folder, $extra, $messageId, $h, $fromEmail, $fromName, $to, $cc, $subject, $text, $p, $size, $date) {
            $msg = MailMessage::create($extra + [
                'user_id' => $account->user_id, 'account_id' => $account->id, 'folder' => $folder,
                'status' => $folder === 'sent' ? 'sent' : 'received', 'message_id' => $messageId,
                'in_reply_to' => trim(MimeParser::header($h, 'in-reply-to'), '<> ') ?: null,
                'thread_key' => MailMessage::threadKey($subject, array_merge([$fromEmail], $to, $cc)),
                'from_email' => $fromEmail, 'from_name' => mb_substr($fromName, 0, 190), 'to' => $to, 'cc' => $cc,
                'subject' => $subject, 'snippet' => mb_substr(preg_replace('/\s+/', ' ', $text), 0, 200),
                'body_text' => $p['text'], 'body_html' => $p['html'], 'has_attachments' => count($p['attachments']) > 0,
                'size' => $size, 'seen' => $folder !== 'inbox', 'sent_at' => date('Y-m-d H:i:s', $date),
            ]);
            foreach ($p['attachments'] as $a) {
                $path = 'mail/' . $account->user_id . '/' . Str::uuid();
                Storage::disk('local')->put($path, $a['content']);
                MailAttachment::create(['message_id' => $msg->id, 'filename' => mb_substr($a['filename'], 0, 190), 'mime' => $a['mime'], 'size' => strlen($a['content']), 'path' => $path]);
            }
            if ($folder === 'inbox') {
                self::applyFilters($msg);
            }

            return $msg;
        });
    }

    public static function applyFilters(MailMessage $msg): void
    {
        foreach (MailFilter::where('user_id', $msg->user_id)->get() as $f) {
            $body = fn () => trim((string) $msg->body_text . ' ' . strip_tags((string) $msg->body_html));
            $hay = match ($f->field) {
                'from' => $msg->from_email . ' ' . $msg->from_name,
                'to' => implode(' ', array_merge($msg->to ?? [], $msg->cc ?? [])),
                'body' => $body(),
                'any' => implode(' ', [$msg->from_email, $msg->from_name, implode(' ', array_merge($msg->to ?? [], $msg->cc ?? [])), $msg->subject, $body()]),
                'attachment' => $msg->attachments()->pluck('filename')->implode(' '),
                default => (string) $msg->subject,
            };
            // "*" on attachments = any attachment at all
            $hit = $f->field === 'attachment' && trim($f->contains) === '*' ? trim($hay) !== '' : ($f->contains !== '' && stripos($hay, $f->contains) !== false);
            if (!$hit) {
                continue;
            }
            match ($f->action) {
                'star' => $msg->starred = true,
                'read' => $msg->seen = true,
                'folder' => in_array($f->value, ['archive', 'spam', 'trash'], true) ? $msg->folder = $f->value : null,
                'label' => $f->value ? $msg->labels()->syncWithoutDetaching([MailLabel::firstOrCreate(['user_id' => $msg->user_id, 'name' => $f->value])->id]) : null,
                default => null,
            };
        }
        $msg->save();
    }

    /** Remove a message and its files for good. */
    public static function purge(MailMessage $msg): void
    {
        foreach ($msg->attachments as $a) {
            if ($a->path) {
                Storage::disk('local')->delete($a->path);
            }
        }
        $msg->delete();
    }

    // ---------------- sending ----------------

    /**
     * Send from one of the user's accounts and keep the copy in Sent.
     * $attachments: [['filename' => .., 'mime' => .., 'content' => ..], ...]
     */
    public static function send(MailAccount $account, array $to, array $cc, array $bcc, string $subject, string $html, array $attachments = [], ?MailMessage $replyTo = null, bool $receipt = false): MailMessage
    {
        if ($account->kind === 'insights') {
            return InsightsMail::send($account, $to, $cc, $bcc, $subject, $html, $attachments); // company mailbox: sent by Insights by Meet
        }
        $settings = MailSetting::for($account->user_id);
        $email = (new Email())
            ->from(new Address($account->email, (string) ($account->display_name ?: $account->user?->full_name)))
            ->subject($subject)
            ->html($html)
            ->text(trim(html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</p>#i', "\n", $html)), ENT_QUOTES)));
        foreach ($to as $a) {
            $email->addTo($a);
        }
        foreach ($cc as $a) {
            $email->addCc($a);
        }
        foreach ($bcc as $a) {
            $email->addBcc($a);
        }
        foreach ($attachments as $a) {
            $email->attach($a['content'], $a['filename'], $a['mime'] ?? null);
        }
        $domain = substr(strrchr($account->email, '@') ?: '@localhost', 1);
        $mid = Str::uuid() . '@' . $domain;
        $email->getHeaders()->addIdHeader('Message-ID', $mid);
        if ($receipt) { // standard read-receipt request (MDN): the other mail program asks its reader before it answers
            $email->getHeaders()->addMailboxHeader('Disposition-Notification-To', $account->email);
        }
        if ($replyTo && $replyTo->message_id) {
            $email->getHeaders()->addIdHeader('In-Reply-To', $replyTo->message_id);
            $email->getHeaders()->addIdHeader('References', $replyTo->message_id);
        }

        if ($account->kind === 'local') {
            Mail::mailer()->getSymfonyTransport()->send($email);
        } else {
            $transport = self::transport($account, $settings);
            (new SymfonyMailer($transport))->send($email);
        }
        $raw = $email->toString();
        $copy = self::ingest($account, $raw, 'sent', ['seen' => true]);
        if ($account->kind === 'imap') {
            try { // keep the provider's Sent folder complete too (Gmail does this by itself)
                $imap = ImapSync::open($account);
                if ($sent = $imap->special('Sent', ['sent', 'sent items', 'sent mail', 'inbox.sent'])) {
                    $imap->append($sent, $raw);
                }
                $imap->logout();
            } catch (\Throwable) {
                // the message is sent; only the server copy is missing
            }
        }
        foreach (array_merge($to, $cc) as $addr) {
            MailContact::firstOrCreate(['user_id' => $account->user_id, 'email' => strtolower($addr)], ['source' => 'sent']);
        }

        $copy ??= MailMessage::where('account_id', $account->id)->where('message_id', $mid)->firstOrFail();
        if ($receipt) {
            $copy->forceFill(['send_meta' => ['receipt' => 'asked'] + (array) $copy->send_meta])->save();
        }

        return $copy;
    }

    /**
     * Read receipts (MDN) and delivery failures (DSN) that come back: noted on the sent message ("Read by ... at ...",
     * "Not delivered: ..."). The report itself still arrives in the Inbox like any mail. Same as Insights by Meet Mail Hub.
     */
    public static function noteReport(MailAccount $account, string $raw, array $h): void
    {
        $type = strtolower(MimeParser::header($h, 'content-type'));
        if (!str_contains($type, 'multipart/report')) {
            return;
        }
        $mdn = str_contains($type, 'disposition-notification');
        $dsn = str_contains($type, 'delivery-status');
        if (!$mdn && !$dsn) {
            return;
        }
        $original = $mdn
            ? (preg_match('/^Original-Message-ID:\s*<?([^>\s]+)>?/mi', $raw, $m) ? $m[1] : null)
            : (preg_match('/^Message-ID:\s*<?([^>\s]+)>?/mi', strstr($raw, 'text/rfc822-headers') ?: (strstr($raw, 'message/rfc822') ?: ''), $m) ? $m[1] : null);
        if (!$original) {
            return;
        }
        $sent = MailMessage::where('user_id', $account->user_id)->where('folder', 'sent')->where('message_id', $original)->first();
        if (!$sent) {
            return;
        }
        $meta = (array) $sent->send_meta;
        if ($mdn) {
            [$who] = MimeParser::address(MimeParser::header($h, 'from'));
            $meta['read'][] = ['by' => $who, 'at' => now()->toIso8601String(), 'how' => preg_match('/Disposition:.*?;\s*([a-z-]+)/i', $raw, $d) ? strtolower($d[1]) : 'displayed'];
        } else {
            $meta['bounce'] = ['at' => now()->toIso8601String(), 'to' => preg_match('/^Final-Recipient:\s*[^;]*;\s*(\S+)/mi', $raw, $r) ? strtolower($r[1]) : null,
                'why' => preg_match('/^Diagnostic-Code:\s*[^;]*;\s*(.+)$/mi', $raw, $w) ? mb_substr(trim($w[1]), 0, 200) : (preg_match('/^Status:\s*(\S+)/mi', $raw, $st) ? 'Status ' . $st[1] : null)];
        }
        $sent->forceFill(['send_meta' => $meta])->save();
    }

    /** example.org is on registrar hold: mail.example.org no longer resolves, the same server answers as mail.example.com. */
    public static function host(string $host): string
    {
        return preg_replace('/(^|\.)example\.(com|in)$/i', '$1example.com', trim($host));
    }

    private static function transport(MailAccount $account, MailSetting $settings): EsmtpTransport
    {
        if ($account->kind === 'google') {
            $t = new EsmtpTransport('smtp.gmail.com', 465, true);
            $t->setUsername($account->email);
            $t->setPassword(GoogleApi::accessToken($settings));
            $t->setAuthenticators([new XOAuth2Authenticator()]);

            return $t;
        }
        $t = new EsmtpTransport(self::host((string) $account->smtp_host), (int) $account->smtp_port, $account->smtp_security === 'ssl');
        if ($account->smtp_security === 'none') {
            $t->setAutoTls(false);
        }
        $t->setUsername((string) ($account->username ?: $account->email));
        $t->setPassword((string) $account->secret);

        return $t;
    }

    // ---------------- vacation reply ----------------

    /**
     * A copy of a new mail to the person's outside address (Settings > Mail on your phone): their phone's mail app (Gmail,
     * Outlook ...) shows it without IMAP. Never for mail that came from that address itself (no loops) or that is spam.
     */
    public static function forwardCopy(MailMessage $msg): void
    {
        $to = strtolower(trim((string) MailSetting::for($msg->user_id)->forward_to));
        $domain = strtolower((string) config('mailbox.domain'));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL) || strtolower((string) $msg->from_email) === $to
            || ($domain !== '' && str_ends_with($to, '@' . $domain)) || ($msg->folder ?? 'inbox') === 'spam') {
            return;
        }
        $from = trim(($msg->from_name ? $msg->from_name . ' ' : '') . '<' . $msg->from_email . '>');
        $head = '<p style="color:#555;font-size:13px">' . e(__('Forwarded by Meet from your address :a. Reply to :f directly, or open Meet to answer from :a.', ['a' => $msg->account->email, 'f' => $from])) . '</p><hr>';
        $body = $msg->body_html ?: nl2br(e((string) $msg->body_text));
        try {
            self::send($msg->account, [$to], [], [], 'Fwd: ' . $msg->subject, $head . $body);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('mailbox forward copy failed: ' . $e->getMessage()); // delivery into Meet is done already
        }
    }

    public static function vacationReply(MailMessage $msg): void
    {
        $s = MailSetting::for($msg->user_id);
        if (!$s->vacation_on || ($s->vacation_until && $s->vacation_until->isPast()) || !$msg->from_email) {
            return;
        }
        // never answer lists, robots or ourselves
        if (preg_match('/^(no-?reply|mailer-daemon|postmaster|bounce)/i', $msg->from_email) || $msg->from_email === $msg->account->email) {
            return;
        }
        $sent = $s->vacation_sent ?? [];
        if (isset($sent[$msg->from_email]) && strtotime($sent[$msg->from_email]) > strtotime('-4 days')) {
            return;
        }
        try {
            self::send($msg->account, [$msg->from_email], [], [], $s->vacation_subject ?: 'Re: ' . $msg->subject, nl2br(e((string) $s->vacation_body)), [], $msg);
            $sent[$msg->from_email] = now()->toDateString();
            $s->vacation_sent = array_slice($sent, -500, null, true);
            $s->save();
        } catch (\Throwable) {
            // an auto-reply must never break delivery
        }
    }
}
