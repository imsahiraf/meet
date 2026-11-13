<?php

namespace Modules\Admin\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Admin\Models\Archive;

// Sent synchronously via ->send() in ArchiveService::deliver() (the
// archive is already built by the time this runs - see
// AdminArchiveController::store()'s comment on why archive creation
// itself is synchronous too).
class ArchiveDeliveryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Archive $archive,
        public readonly ?string $attachmentPath,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Meet system archive - '.$this->archive->created_at->format('Y-m-d'),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'admin::mail.archive-delivery');
    }

    public function attachments(): array
    {
        if (! $this->attachmentPath) {
            return [];
        }

        return [Attachment::fromPath($this->attachmentPath)->as('meet-archive-'.$this->archive->uuid.'.zip')];
    }
}
