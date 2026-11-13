<?php

namespace Modules\Meetings\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingWaitingRoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'participant_type' => $this->participant_type,
            'display_name' => $this->displayName(),
            'requested_at' => $this->requested_at?->toISOString(),
        ];
    }
}
