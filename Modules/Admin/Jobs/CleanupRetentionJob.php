<?php

namespace Modules\Admin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Models\ActivityLog;
use Modules\Admin\Models\Archive;
use Modules\Admin\Models\RetentionPolicy;
use Modules\Media\Models\Media;

/**
 * FR-065 File Expiration + FR-354 archive/log retention - deletes
 * rows (and, for media, their underlying files) past each category's
 * configured cutoff. A null retention_value means "Never" and is
 * skipped entirely.
 */
// Deliberately NOT ShouldQueue: this runs directly inside
// the scheduler process (a single reliable cron running
// `schedule:run`), instead of silently depending on a
// second, easy-to-forget queue-worker cron also being
// alive - see RegistrationOtpNotification's docblock for
// the same issue found and fixed for OTP/password-reset
// mail. Every job here is a fast, bounded DB operation with
// no reason to be deferred anyway.
class CleanupRetentionJob
{
    use Dispatchable, Queueable, SerializesModels;

    public function handle(): void
    {
        $this->cleanupMedia();
        $this->cleanupCategory(RetentionPolicy::ACTIVITY_LOGS, fn ($cutoff) => ActivityLog::where('created_at', '<', $cutoff)->delete());
        $this->cleanupArchives();
    }

    private function policy(string $category): ?RetentionPolicy
    {
        return RetentionPolicy::where('category', $category)->first();
    }

    private function cleanupMedia(): void
    {
        $policy = $this->policy(RetentionPolicy::MEDIA);
        $cutoff = $policy?->cutoff();

        if (! $cutoff) {
            return;
        }

        // One View media has its own self-destruct lifecycle
        // (view-once semantics) - excluded from the generic retention
        // sweep so it isn't double-managed by two different policies.
        Media::where('created_at', '<', $cutoff)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('messages')
                    ->whereColumn('messages.media_id', 'media.id')
                    ->where('messages.message_type', 'one_view');
            })
            ->get()
            ->each(function (Media $media) {
                Storage::disk($media->disk)->delete($media->path);
                $media->delete();
            });
    }

    private function cleanupCategory(string $category, \Closure $deleter): void
    {
        $cutoff = $this->policy($category)?->cutoff();

        if ($cutoff) {
            $deleter($cutoff);
        }
    }

    private function cleanupArchives(): void
    {
        $cutoff = $this->policy(RetentionPolicy::ARCHIVES)?->cutoff();

        if (! $cutoff) {
            return;
        }

        Archive::where('created_at', '<', $cutoff)->get()->each(function (Archive $archive) {
            if ($archive->disk_path) {
                Storage::disk('local')->delete($archive->disk_path);
            }
            $archive->delete();
        });
    }
}
