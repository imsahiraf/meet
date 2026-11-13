<?php

namespace Modules\Meetings\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Meetings\Models\Meeting;

// Sent synchronously via ->send() in CalendarService (not ->queue())
// - see that call site's comment for why.
class MeetingInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Meeting $meeting,
        public readonly string $organizerName,
        public readonly string $joinUrl,
        public readonly string $icsContent,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Meeting invitation: '.$this->meeting->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'meetings::mail.meeting-invitation',
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->icsContent, 'invite.ics')
                ->withMime('text/calendar'),
        ];
    }
}
