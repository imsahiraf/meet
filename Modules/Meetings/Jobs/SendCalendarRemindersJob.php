<?php

namespace Modules\Meetings\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Meetings\Services\CalendarService;

// Deliberately NOT ShouldQueue: this runs directly inside
// the scheduler process (a single reliable cron running
// `schedule:run`), instead of silently depending on a
// second, easy-to-forget queue-worker cron also being
// alive - see RegistrationOtpNotification's docblock for
// the same issue found and fixed for OTP/password-reset
// mail. Every job here is a fast, bounded DB operation with
// no reason to be deferred anyway.
class SendCalendarRemindersJob
{
    use Dispatchable, Queueable, SerializesModels;

    public function handle(CalendarService $calendar): void
    {
        $calendar->sendDueReminders();
    }
}
