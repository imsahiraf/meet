<?php

namespace Modules\Chat\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Chat\Models\TypingStatus;

/**
 * FR-039: "Indicator disappears automatically after timeout." The API
 * already ignores stale rows when reading (ConversationRepository::
 * activeTypingUsers), this job just keeps the table from growing forever.
 */
// Deliberately NOT ShouldQueue: this runs directly inside
// the scheduler process (a single reliable cron running
// `schedule:run`), instead of silently depending on a
// second, easy-to-forget queue-worker cron also being
// alive - see RegistrationOtpNotification's docblock for
// the same issue found and fixed for OTP/password-reset
// mail. Every job here is a fast, bounded DB operation with
// no reason to be deferred anyway.
class CleanupStaleTypingStatusJob
{
    use Dispatchable, Queueable, SerializesModels;

    public function handle(): void
    {
        TypingStatus::where('updated_at', '<', now()->subSeconds(config('chat.typing_timeout_seconds') * 2))
            ->delete();
    }
}
