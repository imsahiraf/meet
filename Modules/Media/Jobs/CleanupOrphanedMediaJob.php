<?php

namespace Modules\Media\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Media\Models\Media;
use Modules\Media\Models\OneViewMedia;

/**
 * Two distinct kinds of residue this app can accumulate without a
 * configured retention policy (CleanupRetentionJob only runs once an
 * admin sets one, and deliberately EXCLUDES One View media entirely):
 *
 * 1. A View Once file is normally already deleted synchronously, right
 *    inside the stream response that serves it to the viewer (see
 *    MediaController::show()) - viewed_at is set BEFORE that delete
 *    runs, so if the connection drops or the process dies mid-stream,
 *    viewed_at ends up true with the file still sitting on disk
 *    forever (nothing else ever revisits an already-"consumed" one).
 *    This is that safety net, not the primary mechanism.
 * 2. A Media row genuinely orphaned - not referenced by any message
 *    (including one still used by a forward - see
 *    MessageRepository::deleteMediaIfOrphaned(), the same rule this
 *    job applies in bulk), any group's media library, or a
 *    whiteboard export - covers delete-for-everyone residue that
 *    predates that fix, and an uploaded-but-never-sent attachment
 *    (media is uploaded via its own endpoint before a message
 *    references it - an abandoned upload leaves a real orphan).
 */
// Deliberately NOT ShouldQueue: this runs directly inside
// the scheduler process (a single reliable cron running
// `schedule:run`), instead of silently depending on a
// second, easy-to-forget queue-worker cron also being
// alive - see RegistrationOtpNotification's docblock for
// the same issue found and fixed for OTP/password-reset
// mail. Every job here is a fast, bounded DB operation with
// no reason to be deferred anyway.
class CleanupOrphanedMediaJob
{
    use Dispatchable, Queueable, SerializesModels;

    public function handle(): void
    {
        $this->cleanupConsumedOneViewMedia();
        $this->cleanupUnreferencedMedia();
    }

    /**
     * A short grace window (not immediate) so a file can't vanish out
     * from under a request that's still actively streaming it back to
     * the viewer at the exact moment this job runs.
     */
    private function cleanupConsumedOneViewMedia(): void
    {
        OneViewMedia::whereNotNull('viewed_at')
            ->where('viewed_at', '<', now()->subMinutes(10))
            ->whereHas('message', fn ($query) => $query->whereNotNull('media_id'))
            ->with('message')
            ->get()
            ->each(function (OneViewMedia $oneView) {
                $media = Media::find($oneView->message->media_id);

                if ($media) {
                    Storage::disk($media->disk)->delete($media->path);
                    // messages.media_id is nullOnDelete - the message
                    // row (and this OneViewMedia row, which is what
                    // drives its "Opened" state) is left untouched.
                    $media->delete();
                }
            });
    }

    private function cleanupUnreferencedMedia(): void
    {
        Media::where('created_at', '<', now()->subDay())
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('messages')
                ->whereColumn('messages.media_id', 'media.id'))
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('group_media')
                ->whereColumn('group_media.media_id', 'media.id'))
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('whiteboard_exports')
                ->whereColumn('whiteboard_exports.media_id', 'media.id'))
            ->get()
            ->each(function (Media $media) {
                Storage::disk($media->disk)->delete($media->path);
                $media->delete();
            });
    }
}
