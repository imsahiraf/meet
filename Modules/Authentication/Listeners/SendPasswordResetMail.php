<?php

namespace Modules\Authentication\Listeners;

use Modules\Authentication\Events\PasswordResetRequested;
use Modules\Authentication\Notifications\PasswordResetNotification;

/**
 * Deliberately NOT queued - see RegistrationOtpNotification's
 * docblock. A queued listener is itself dispatched as a job, so even
 * with the notification made synchronous, this would still silently
 * depend on a queue worker being alive without this change too.
 */
class SendPasswordResetMail
{
    public function handle(PasswordResetRequested $event): void
    {
        $event->user->notify(new PasswordResetNotification($event->resetToken->token));
    }
}
