<?php

namespace Modules\Admin\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Sent synchronously (not ShouldQueue) - matches PasswordResetMail's
// reasoning: this app has no persistent queue worker, and a mail that
// carries a usable credential shouldn't sit deferred anyway.
class TemporaryPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $fullName,
        public readonly string $temporaryPassword,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your password was reset - ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'admin::mail.temporary-password',
        );
    }
}
