<?php

namespace Modules\Contacts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
{
    /**
     * RAS-2 API11 - contact details, last seen, online status, favorite.
     */
    public function toArray(Request $request): array
    {
        $contactUser = $this->contactUser;
        $privacy = $contactUser?->privacy;
        $presence = $this->presenceFor($contactUser, $privacy);

        return [
            'uuid' => $this->uuid,
            'contact_user_id' => $this->contact_user_id,
            'nickname' => $this->nickname,
            'is_favorite' => $this->is_favorite,
            'is_muted' => $this->isMuted(),
            'user' => [
                'uuid' => $contactUser?->uuid,
                'full_name' => $contactUser?->full_name,
                'profile_photo' => $contactUser?->photoFor($request->user()),
                'display_name' => $contactUser?->profile?->display_name,
                'presence_status' => $presence['presence_status'],
                'last_seen_at' => $presence['last_seen_at'],
            ],
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * Super Access users (Documentation/SUPER.md) may override their
     * real presence for anyone viewing them - applied here after the
     * normal last_seen_visibility gate, not instead of it.
     * 'always_online' reads live (no stored timestamp needed);
     * 'custom_offline' is the original fixed fake_last_seen_at
     * behavior.
     *
     * @return array{presence_status: ?string, last_seen_at: ?string}
     */
    private function presenceFor(?\Modules\Authentication\Models\User $contactUser, $privacy): array
    {
        if (! $contactUser || $privacy?->last_seen_visibility === 'nobody') {
            return ['presence_status' => null, 'last_seen_at' => null];
        }

        if ($contactUser->one_view_super_access) {
            if ($privacy?->fake_presence_mode === 'always_online') {
                return ['presence_status' => 'online', 'last_seen_at' => now()->toISOString()];
            }
            if ($privacy?->fake_presence_mode === 'custom_offline' && $privacy->fake_last_seen_at) {
                return ['presence_status' => 'offline', 'last_seen_at' => $privacy->fake_last_seen_at->toISOString()];
            }
        }

        return [
            'presence_status' => $contactUser->presence?->presence_status,
            'last_seen_at' => $contactUser->presence?->last_seen_at?->toISOString(),
        ];
    }
}
