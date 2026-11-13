<?php

namespace Modules\Groups\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupJoinRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'user_id' => $this->user_id,
            'full_name' => $this->user->full_name,
            'message' => $this->message,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
