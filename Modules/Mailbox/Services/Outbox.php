<?php

namespace Modules\Mailbox\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Models\MailAttachment;
use Modules\Mailbox\Models\MailMessage;

/**
 * Mail that leaves later: "Send later" (a time the person picks) and "Undo send" (a few seconds' wait, setting per person).
 * The message waits in the Scheduled folder with its attachments; `mailbox:due` (every minute) sends it and also brings
 * snoozed mail back to the inbox. Cancelling a scheduled message turns it into a draft again.
 */
class Outbox
{
    public static function queue(MailAccount $account, array $to, array $cc, array $bcc, string $subject, string $html, array $files, ?MailMessage $reply, \DateTimeInterface $at): MailMessage
    {
        $m = MailMessage::create(['user_id' => $account->user_id, 'account_id' => $account->id, 'folder' => 'scheduled', 'status' => 'sending', 'to' => $to, 'cc' => $cc, 'bcc' => $bcc,
            'subject' => $subject, 'body_html' => $html, 'snippet' => mb_substr(trim(strip_tags($html)), 0, 200), 'from_email' => $account->email, 'seen' => true,
            'send_at' => $at, 'sent_at' => $at, 'reply_ref_id' => $reply?->id, 'has_attachments' => $files !== []]);
        foreach ($files as $f) {
            $path = 'mail/' . $account->user_id . '/outbox/' . $m->id . '-' . \Illuminate\Support\Str::random(12);
            Storage::disk('local')->put($path, $f['content']);
            MailAttachment::create(['message_id' => $m->id, 'filename' => mb_substr($f['filename'], 0, 190), 'mime' => $f['mime'] ?? null, 'size' => strlen($f['content']), 'path' => $path]);
        }

        return $m;
    }

    /** Send what is due and wake snoozed mail. Returns [sent, failed, woken]. */
    public static function run(): array
    {
        $sent = $failed = 0;
        foreach (MailMessage::where('folder', 'scheduled')->where('status', 'sending')->where('send_at', '<=', now())->with('attachments')->limit(100)->get() as $m) {
            $account = MailAccount::find($m->account_id);
            try {
                if (!$account) {
                    throw new \RuntimeException('The account is gone.');
                }
                $files = $m->attachments->filter(fn ($a) => $a->path && Storage::disk('local')->exists($a->path))
                    ->map(fn ($a) => ['filename' => $a->filename, 'mime' => $a->mime, 'content' => Storage::disk('local')->get($a->path)])->values()->all();
                $reply = $m->reply_ref_id ? MailMessage::where('user_id', $m->user_id)->find($m->reply_ref_id) : null;
                Mailbox::send($account, (array) $m->to, (array) $m->cc, (array) $m->bcc, (string) $m->subject, (string) $m->body_html, $files, $reply, !empty(((array) $m->send_meta)['receipt_wanted']));
                Mailbox::purge($m); // the Sent copy is made by send()
                $sent++;
            } catch (\Throwable $e) {
                $m->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 290)]);
                Log::warning("mailbox:due message {$m->id}: " . $e->getMessage());
                $failed++;
            }
        }
        $woken = MailMessage::where('folder', 'snoozed')->where('snooze_until', '<=', now())->update(['folder' => 'inbox', 'seen' => false, 'snooze_until' => null]);

        return [$sent, $failed, $woken];
    }

    /** A scheduled message back to drafts (Cancel / Undo). */
    public static function cancel(MailMessage $m): void
    {
        foreach ($m->attachments as $a) { // drafts keep no attachments: they are added again when sending
            if ($a->path) {
                Storage::disk('local')->delete($a->path);
            }
            $a->delete();
        }
        $m->update(['folder' => 'drafts', 'status' => 'draft', 'send_at' => null, 'error' => null, 'has_attachments' => false]);
    }
}
