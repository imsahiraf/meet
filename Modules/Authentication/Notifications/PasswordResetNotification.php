<?php

namespace Modules\Authentication\Notifications;

use Illuminate\Notifications\Notification;
use Modules\Authentication\Mail\PasswordResetMail;

/**
 * Deliberately NOT queued - see RegistrationOtpNotification's
 * docblock for why: a password-reset email is a time-critical,
 * user-is-actively-waiting send, and queuing it makes delivery
 * silently depend on a queue worker that may not be running.
 */
class PasswordResetNotification extends Notification
{
    public function __construct(
        public readonly string $resetToken,
    ) {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    /**
     * A Mailable returned from toMail() does not get its recipient set
     * automatically (unlike MailMessage) - ->to() must be explicit.
     */
    public function toMail($notifiable): PasswordResetMail
    {
        return (new PasswordResetMail(
            $notifiable->full_name,
            $this->resetToken,
            config('authentication.password_reset.token_expires_in_minutes'),
        ))->to($notifiable->email);
    }
}
