<?php

namespace Modules\Chat\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Chat\Models\Message;
use Modules\Chat\Repositories\MessageRepository;

// Deliberately NOT ShouldQueue - see CleanupStaleTypingStatusJob's
// docblock for why every job in this app runs synchronously off the
// scheduler process instead of a separate queue worker.
class CleanupExpiredDisappearingMessagesJob
{
    use Dispatchable, Queueable, SerializesModels;

    public function handle(MessageRepository $messages): void
    {
        // purgeExpired(), not deleteForEveryone() - a disappearing
        // message that hits its expiry should be genuinely gone (row and
        // media both), not soft-deleted into a "This message was
        // deleted" tombstone the way an explicit user delete-for-everyone
        // is. See MessageRepository::purgeExpired()'s docblock.
        Message::whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get()
            ->each(fn (Message $message) => $messages->purgeExpired($message));
    }
}
