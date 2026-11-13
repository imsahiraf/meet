<?php

namespace Modules\Authentication\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Authentication\Models\EmailOtp;

/**
 * FR-002 business rule: "Expired records shall be removed automatically
 * by scheduled cleanup jobs." Registered on the scheduler (routes/console.php).
 */
// Deliberately NOT ShouldQueue: this runs directly inside
// the scheduler process (a single reliable cron running
// `schedule:run`), instead of silently depending on a
// second, easy-to-forget queue-worker cron also being
// alive - see RegistrationOtpNotification's docblock for
// the same issue found and fixed for OTP/password-reset
// mail. Every job here is a fast, bounded DB operation with
// no reason to be deferred anyway.
class CleanupExpiredOtpsJob
{
    use Dispatchable, Queueable, SerializesModels;

    public function handle(): void
    {
        EmailOtp::where('status', 'pending')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);

        EmailOtp::whereIn('status', ['expired', 'verified', 'blocked'])
            ->where('updated_at', '<', now()->subDays(7))
            ->delete();
    }
}
