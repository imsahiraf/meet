<?php

namespace Modules\Admin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Admin\Models\Archive;
use Modules\Admin\Models\ArchiveSetting;
use Modules\Admin\Services\ArchiveService;

/**
 * FR-347 - the underlying Laravel schedule entry (routes/console.php)
 * runs this daily since schedule_frequency is admin-configurable at
 * runtime, not fixed at deploy time the way ->weekly()/->monthly()
 * would require; this job itself decides whether enough time has
 * actually passed since the last scheduled archive for the currently
 * configured frequency (or does nothing for "manual_only").
 */
// Deliberately NOT ShouldQueue: this runs directly inside
// the scheduler process (a single reliable cron running
// `schedule:run`), instead of silently depending on a
// second, easy-to-forget queue-worker cron also being
// alive - see RegistrationOtpNotification's docblock for
// the same issue found and fixed for OTP/password-reset
// mail. Every job here is a fast, bounded DB operation with
// no reason to be deferred anyway.
class ScheduledArchiveJob
{
    use Dispatchable, Queueable, SerializesModels;

    private const INTERVALS = ['daily' => 1, 'weekly' => 7, 'monthly' => 30];

    public function handle(ArchiveService $archives): void
    {
        $settings = ArchiveSetting::current();
        $intervalDays = self::INTERVALS[$settings->schedule_frequency] ?? null;

        if (! $intervalDays) {
            return;
        }

        $lastScheduled = Archive::where('trigger', 'scheduled')->latest('created_at')->first();

        if ($lastScheduled && $lastScheduled->created_at->diffInDays(now()) < $intervalDays) {
            return;
        }

        $archive = $archives->create(null, 'entire_system', 'scheduled');
        CreateArchiveJob::dispatchSync($archive);
    }
}
