<?php

namespace Modules\Groups\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupInviteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'invite_token' => $this->invite_token,
            'invite_url' => url('/groups/join/'.$this->invite_token),
            'expires_at' => $this->expires_at,
            'invited_user_id' => $this->invited_user_id,
        ];
    }
}
