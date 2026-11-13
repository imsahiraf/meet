<?php

namespace Modules\Authentication\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Sent synchronously (not ShouldQueue) via RegistrationOtpNotification
// - see that class's docblock.
class RegistrationOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $fullName,
        public readonly string $otpCode,
        public readonly int $expiresInMinutes,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verify your email - ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'authentication::mail.registration-otp',
        );
    }
}
