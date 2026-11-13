<?php

namespace Modules\User\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\User\Models\UserPresence;

/**
 * FR-032: presence is heartbeat-driven (the browser pings every 20s
 * while a page is open) rather than event-driven, so there's no signal
 * when a tab is closed/network drops. This job is what actually turns
 * "online" back to "offline" once the heartbeat stops arriving.
 */
// Deliberately NOT ShouldQueue: this runs directly inside
// the scheduler process (a single reliable cron running
// `schedule:run`), instead of silently depending on a
// second, easy-to-forget queue-worker cron also being
// alive - see RegistrationOtpNotification's docblock for
// the same issue found and fixed for OTP/password-reset
// mail. Every job here is a fast, bounded DB operation with
// no reason to be deferred anyway.
class MarkStalePresenceOfflineJob
{
    use Dispatchable, Queueable, SerializesModels;

    public function handle(): void
    {
        UserPresence::whereIn('presence_status', ['online', 'away', 'busy'])
            ->where('updated_at', '<', now()->subMinutes(2))
            ->update(['presence_status' => 'offline']);
    }
}
