<?php

namespace Modules\Groups\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'user_id' => $this->user_id,
            'full_name' => $this->user->full_name,
            'role' => [
                'id' => $this->role->id,
                'name' => $this->role->role_name,
            ],
            'joined_at' => $this->joined_at,
            'is_muted' => $this->is_muted,
        ];
    }
}
