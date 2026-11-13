<?php

namespace Modules\Groups\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'event_start' => $this->event_start,
            'event_end' => $this->event_end,
            'created_by' => $this->creator?->full_name,
        ];
    }
}
