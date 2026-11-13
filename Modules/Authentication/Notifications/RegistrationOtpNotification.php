<?php

namespace Modules\Authentication\Notifications;

use Illuminate\Notifications\Notification;
use Modules\Authentication\Mail\RegistrationOtpMail;

/**
 * Deliberately NOT queued (ShouldQueue removed): a registration OTP is
 * a time-critical, user-is-actively-waiting send. Queuing it made
 * delivery depend on a queue worker actually being alive to process
 * it - shared/cPanel hosting has no persistent worker at all (only a
 * cron-triggered one, see DEPLOYMENT.md), and this dev environment
 * had none running either, so every OTP silently sat in the `jobs`
 * table forever and was never sent, no matter how many times a user
 * hit "resend" - a real, confirmed bug, not a hypothetical one.
 */
class RegistrationOtpNotification extends Notification
{
    public function __construct(
        public readonly string $otpCode,
    ) {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    /**
     * Reuses the existing markdown Mailable - Notification::toMail() may
     * return a Mailable directly instead of building a MailMessage, but
     * unlike MailMessage, a returned Mailable does NOT get its recipient
     * set automatically, so ->to() must be called explicitly here.
     */
    public function toMail($notifiable): RegistrationOtpMail
    {
        return (new RegistrationOtpMail(
            $notifiable->full_name,
            $this->otpCode,
            config('authentication.otp.expires_in_minutes'),
        ))->to($notifiable->email);
    }
}
