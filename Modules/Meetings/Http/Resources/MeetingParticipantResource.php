<?php

namespace Modules\Meetings\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'is_guest' => $this->isGuest(),
            'display_name' => $this->displayName(),
            'role' => $this->role,
            'joined_at' => $this->joined_at?->toISOString(),
            'microphone_enabled' => $this->microphone_enabled,
            'camera_enabled' => $this->camera_enabled,
            'screen_sharing' => $this->screen_sharing,
            'hand_raised' => $this->hand_raised,
        ];
    }
}
