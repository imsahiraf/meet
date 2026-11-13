<?php

namespace Modules\Groups\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Groups\Models\GroupInvite;

/**
 * RAS-3 "Queue Jobs" list - marks invites whose expires_at has passed
 * so they read as expired instead of staying "pending" forever.
 */
// Deliberately NOT ShouldQueue: this runs directly inside
// the scheduler process (a single reliable cron running
// `schedule:run`), instead of silently depending on a
// second, easy-to-forget queue-worker cron also being
// alive - see RegistrationOtpNotification's docblock for
// the same issue found and fixed for OTP/password-reset
// mail. Every job here is a fast, bounded DB operation with
// no reason to be deferred anyway.
class CleanupExpiredInvitesJob
{
    use Dispatchable, Queueable, SerializesModels;

    public function handle(): void
    {
        GroupInvite::where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);
    }
}
