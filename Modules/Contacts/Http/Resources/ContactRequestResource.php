<?php

namespace Modules\Contacts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'status' => $this->status,
            'message' => $this->message,
            'sender' => [
                'uuid' => $this->sender?->uuid,
                'full_name' => $this->sender?->full_name,
                'profile_photo' => $this->sender?->photoFor($request->user()),
            ],
            'receiver' => [
                'uuid' => $this->receiver?->uuid,
                'full_name' => $this->receiver?->full_name,
                'profile_photo' => $this->receiver?->profile_photo,
            ],
            'responded_at' => $this->responded_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
