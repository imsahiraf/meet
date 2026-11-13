<?php

namespace Modules\Meetings\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuestSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'meeting_id' => $this->meeting_id,
            'guest_name' => $this->guest_name,
            'session_token' => $this->session_token,
            'status' => $this->status,
            'expires_at' => $this->expires_at?->toISOString(),
        ];
    }
}
