<?php

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * RAS-2 API1 - the authenticated user's complete profile bundle:
 * basic info, profile info, preferences, privacy, presence.
 */
class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'profile_photo' => $this->profile_photo,
            'status' => $this->status,
            'profile' => $this->whenLoaded('profile'),
            'preferences' => $this->whenLoaded('preferences'),
            'privacy' => $this->whenLoaded('privacy'),
            'presence' => $this->whenLoaded('presence'),
        ];
    }
}
