<?php

namespace Modules\Media\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'media_type' => $this->media_type,
            'is_voice_note' => $this->is_voice_note,
            'duration_seconds' => $this->duration_seconds,
            'url' => url('/api/v1/media/'.$this->id),
        ];
    }
}
