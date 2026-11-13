<?php

namespace Modules\Authentication\Listeners;

use Modules\Authentication\Events\UserRegistered;
use Modules\Authentication\Notifications\RegistrationOtpNotification;

/**
 * Deliberately NOT queued - see RegistrationOtpNotification's
 * docblock. A queued listener is itself dispatched as a job, so even
 * with the notification made synchronous, this would still silently
 * depend on a queue worker being alive without this change too.
 */
class SendRegistrationOtpMail
{
    public function handle(UserRegistered $event): void
    {
        $event->user->notify(new RegistrationOtpNotification($event->otpCode));
    }
}
