<?php

namespace Modules\Groups\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupAnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'announcement' => $this->announcement,
            'is_pinned' => $this->is_pinned,
            'created_by' => $this->creator?->full_name,
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
        ];
    }
}
