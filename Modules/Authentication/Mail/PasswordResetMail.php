<?php

namespace Modules\Authentication\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Sent synchronously (not ShouldQueue) via PasswordResetNotification
// - see that class's docblock.
class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $fullName,
        public readonly string $resetToken,
        public readonly int $expiresInMinutes,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your password - ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'authentication::mail.password-reset',
        );
    }
}
