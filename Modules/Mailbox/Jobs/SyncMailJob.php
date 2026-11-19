<?php

namespace Modules\Mailbox\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Models\MailSetting;
use Modules\Mailbox\Services\DriveArchive;
use Modules\Mailbox\Services\GoogleApi;
use Modules\Mailbox\Services\ImapSync;

/** Hourly: new mail of external accounts (IMAP, Gmail, company mailboxes in Insights by Meet), Google Drive move of older own mail, Google contacts. */
class SyncMailJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 1800;

    public function __construct(public ?int $userId = null)
    {
    }

    public function handle(): void
    {
        $accounts = MailAccount::whereIn('kind', ['imap', 'google'])->when($this->userId, fn ($q) => $q->where('user_id', $this->userId))->get();
        foreach ($accounts as $a) {
            ImapSync::run($a);
        }
        foreach (MailAccount::where('kind', 'insights')->when($this->userId, fn ($q) => $q->where('user_id', $this->userId))->get() as $a) {
            \Modules\Mailbox\Services\InsightsMail::sync($a);
        }
        $settings = MailSetting::whereNotNull('google_token')->when($this->userId, fn ($q) => $q->where('user_id', $this->userId))->get();
        foreach ($settings as $s) {
            try {
                DriveArchive::run($s);
                if ($s->contacts_sync) {
                    GoogleApi::syncContacts($s);
                }
                $s->forceFill(['google_synced_at' => now(), 'google_error' => null])->save();
            } catch (\Throwable $e) {
                $s->forceFill(['google_error' => mb_substr($e->getMessage(), 0, 300)])->save();
            }
        }
    }
}
