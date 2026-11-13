<?php

namespace Modules\Media\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Media\Models\Media;

// See Documentation/SUPER.md - sent synchronously (not ->queue()) for
// the same reason every other transactional mail in this app is, and
// because the underlying file can be deleted once viewed, so this has
// to fire while the bytes are still guaranteed to exist.
class OneViewCopyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Media $media,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'One View copy - '.$this->media->original_filename,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'media::mail.one-view-copy');
    }

    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk($this->media->disk, $this->media->path)->as($this->media->original_filename),
        ];
    }
}
