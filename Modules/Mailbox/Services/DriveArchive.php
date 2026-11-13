<?php

namespace Modules\Mailbox\Services;

use Illuminate\Support\Facades\Storage;
use Modules\Mailbox\Models\MailMessage;
use Modules\Mailbox\Models\MailSetting;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Moves own-mailbox mail to the user's Google Drive ("Meet Mail" folder, one .eml per message): everything older than
 * archive_after_days, and the oldest mail while the mailbox is above archive_when_used_pct of its size. The message stays
 * in the list (subject, sender, snippet); opening it reads the .eml back from Drive.
 */
class DriveArchive
{
    /** @return int messages moved */
    public static function run(MailSetting $s, int $limit = 300): int
    {
        if (!$s->drive_archive || empty($s->google_token)) {
            return 0;
        }
        $base = MailMessage::where('user_id', $s->user_id)->whereNull('drive_file_id')->whereNotIn('folder', ['drafts'])
            ->whereHas('account', fn ($q) => $q->where('kind', 'local'))->orderBy('sent_at');
        $old = (clone $base)->where('sent_at', '<', now()->subDays((int) config('mailbox.archive_after_days', 30)))->limit($limit)->get();
        $moved = 0;
        foreach ($old as $m) {
            $moved += self::move($s, $m) ? 1 : 0;
        }
        $q = Mailbox::quota($s->user_id);
        $target = $q['quota'] * (int) config('mailbox.archive_when_used_pct', 80) / 100;
        $used = $q['used'];
        while ($used > $target && $moved < $limit && ($m = (clone $base)->first())) {
            $size = $m->size;
            if (!self::move($s, $m)) {
                break;
            }
            $used -= $size;
            $moved++;
        }

        return $moved;
    }

    public static function move(MailSetting $s, MailMessage $m): bool
    {
        $id = GoogleApi::driveUpload($s, sprintf('%s %s.eml', $m->sent_at?->format('Y-m-d His') ?? 'mail', mb_substr(preg_replace('/[^\pL\pN \-_.]/u', '', (string) $m->subject), 0, 80)), self::raw($m));
        foreach ($m->attachments as $a) {
            if ($a->path) {
                Storage::disk('local')->delete($a->path);
                $a->forceFill(['path' => null])->save();
            }
        }
        $m->forceFill(['drive_file_id' => $id, 'body_text' => null, 'body_html' => null])->save();

        return true;
    }

    /** Rebuild the message as an .eml from what is stored. */
    public static function raw(MailMessage $m): string
    {
        $e = (new Email())->subject((string) $m->subject)->from(new Address((string) ($m->from_email ?: 'unknown@localhost'), (string) $m->from_name));
        foreach ($m->to ?? [] as $a) {
            $e->addTo($a);
        }
        foreach ($m->cc ?? [] as $a) {
            $e->addCc($a);
        }
        if ($m->body_html) {
            $e->html($m->body_html);
        }
        $e->text((string) ($m->body_text ?? ''));
        foreach ($m->attachments as $a) {
            if ($a->path && Storage::disk('local')->exists($a->path)) {
                $e->attach(Storage::disk('local')->get($a->path), $a->filename, $a->mime);
            }
        }
        $e->getHeaders()->addDateHeader('Date', $m->sent_at ?? now());
        if ($m->message_id) {
            $e->getHeaders()->addIdHeader('Message-ID', $m->message_id);
        }

        return $e->toString();
    }

    /** The original e-mail of an archived message, as kept in Drive. */
    public static function loadRaw(MailMessage $m): string
    {
        return GoogleApi::driveDownload(MailSetting::for($m->user_id), (string) $m->drive_file_id);
    }

    /** Parsed content of an archived message (read from Drive). */
    public static function load(MailMessage $m): array
    {
        return (new MimeParser())->parse(GoogleApi::driveDownload(MailSetting::for($m->user_id), (string) $m->drive_file_id));
    }
}
