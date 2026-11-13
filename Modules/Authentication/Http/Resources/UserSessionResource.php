<?php

namespace Modules\Authentication\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'device_name' => $this->device_name,
            'platform' => $this->platform,
            'browser' => $this->browser,
            'operating_system' => $this->operating_system,
            'ip_address' => $this->ip_address,
            'last_activity_at' => $this->last_activity_at?->toISOString(),
            'is_current_device' => $this->session_id === (string) $request->user()?->currentAccessToken()?->id,
        ];
    }
}
