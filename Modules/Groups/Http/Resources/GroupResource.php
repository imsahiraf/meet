<?php

namespace Modules\Groups\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'conversation_id' => $this->conversation_id,
            'owner_id' => $this->owner_id,
            'name' => $this->name,
            'description' => $this->description,
            'avatar' => $this->avatar,
            'member_count' => $this->member_count ?? $this->activeMembers()->count(),
            'member_limit' => $this->member_limit,
            'approval_required' => $this->approval_required,
            'settings' => [
                'admin_only_messaging' => $this->admin_only_messaging,
                'admin_only_edit_info' => $this->admin_only_edit_info,
                'admin_only_invite' => $this->admin_only_invite,
                'admin_only_pin' => $this->admin_only_pin,
                'allow_member_reactions' => $this->allow_member_reactions,
                'allow_member_file_sharing' => $this->allow_member_file_sharing,
            ],
            'status' => $this->status,
            'last_activity_at' => $this->conversation?->last_activity_at,
            'created_at' => $this->created_at,
        ];
    }
}
