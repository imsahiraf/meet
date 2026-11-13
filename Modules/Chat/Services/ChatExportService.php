<?php

namespace Modules\Chat\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\Message;
use ZipArchive;

/**
 * Downloadable transcript + attached media, distinct from the
 * admin-side Archive/Backup-Mail engine (ArchiveService) - this is a
 * per-conversation export the conversation's own members can trigger,
 * not a system-wide backup.
 */
class ChatExportService
{
    private const ONE_VIEW_TYPES = ['one_view'];

    public function export(User $user, Conversation $conversation): string
    {
        $messages = Message::where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->with(['sender', 'media', 'oneViewMedia'])
            ->get()
            ->reject(fn (Message $message) => $message->isDeletedFor($user->id));

        $tmpDir = storage_path('app/private/chat-exports-tmp');
        File::ensureDirectoryExists($tmpDir);
        $zipPath = $tmpDir.'/'.(string) Str::uuid().'.zip';

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('transcript.csv', $this->buildTranscriptCsv($messages));

        foreach ($messages as $message) {
            $this->addMediaToZip($zip, $message);
        }

        $zip->close();

        return $zipPath;
    }

    private function buildTranscriptCsv(\Illuminate\Support\Collection $messages): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['Timestamp', 'Sender', 'Type', 'Message']);

        foreach ($messages as $message) {
            fputcsv($handle, [
                $message->created_at->toDateTimeString(),
                $message->sender->full_name,
                $message->message_type,
                $this->transcriptBody($message),
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function transcriptBody(Message $message): string
    {
        if (in_array($message->message_type, self::ONE_VIEW_TYPES, true)) {
            return $message->oneViewMedia?->isConsumed()
                ? '[One View media - already viewed, no longer available]'
                : '[One View media]';
        }

        if ($message->media) {
            return '[Attachment: '.$message->media->original_filename.']';
        }

        // Server-side export can't decrypt an E2E message's body - the
        // server never holds the private key that would be needed to.
        // A raw ciphertext blob in the CSV would be meaningless, so a
        // clear placeholder goes there instead, same pattern as One
        // View/attachments above.
        if ($message->encryption_mode === 'e2e') {
            return '[End-to-end encrypted message - not readable in this export]';
        }

        return (string) $message->body;
    }

    /**
     * One-view media already consumed by a non-sender viewer is
     * genuinely deleted from disk (see MediaController::streamOneViewMedia),
     * so it's silently skipped here rather than erroring - same reason
     * any missing file on disk is just skipped, defensively.
     */
    private function addMediaToZip(ZipArchive $zip, Message $message): void
    {
        $media = $message->media;

        if (! $media) {
            return;
        }

        if (in_array($message->message_type, self::ONE_VIEW_TYPES, true) && $message->oneViewMedia?->isConsumed()) {
            return;
        }

        $disk = Storage::disk($media->disk);

        if (! $disk->exists($media->path)) {
            return;
        }

        $zip->addFile($disk->path($media->path), 'media/'.$message->id.'-'.$media->original_filename);
    }
}
