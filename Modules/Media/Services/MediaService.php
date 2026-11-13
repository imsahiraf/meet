<?php

namespace Modules\Media\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\Message;
use Modules\Media\Exceptions\UnsupportedMediaTypeException;
use Modules\Media\Models\Media;

class MediaService
{
    /**
     * Single-request multipart upload rather than the chunked/resumable
     * protocol the SRS describes - a disclosed scope reduction (see
     * Media/config/config.php).
     */
    public function store(User $uploader, UploadedFile $file, bool $isVoiceNote = false, ?int $durationSeconds = null): Media
    {
        // Voice notes are recorded as audio-only WebM, but PHP's finfo
        // sniffs WebM containers as video/webm regardless of whether
        // they carry an audio-only track (the container signature
        // doesn't distinguish audio-only vs. audio+video) - trust the
        // explicit is_voice_note flag over MIME sniffing here.
        $mediaType = $isVoiceNote ? 'audio' : $this->resolveMediaType($file->getMimeType());

        $path = $file->storeAs(
            'media/'.date('Y/m'),
            Str::uuid().'.'.$file->getClientOriginalExtension(),
            'local',
        );

        return Media::create([
            'uploader_id' => $uploader->id,
            'disk' => 'local',
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'media_type' => $mediaType,
            'is_voice_note' => $isVoiceNote,
            'duration_seconds' => $durationSeconds,
        ]);
    }

    public function resolveMediaType(string $mimeType): string
    {
        foreach (config('media.allowed_mime_types') as $type => $mimes) {
            if (in_array($mimeType, $mimes, true)) {
                return $type;
            }
        }

        throw new UnsupportedMediaTypeException();
    }

    /**
     * A user may access (view/download) media if they uploaded it, or
     * if they're a member of any conversation containing a message
     * that references it - group messages are ordinary Message rows
     * too, so this covers both 1:1 and group media uniformly.
     */
    public function canAccess(User $user, Media $media): bool
    {
        if ($media->uploader_id === $user->id) {
            return true;
        }

        return Message::where('media_id', $media->id)
            ->whereHas('conversation.members', fn ($q) => $q->where('user_id', $user->id))
            ->exists();
    }

    public function messageTypeFor(Media $media): string
    {
        return $media->media_type === 'document' ? 'file' : $media->media_type;
    }
}
