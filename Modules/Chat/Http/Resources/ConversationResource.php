<?php

namespace Modules\Chat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Chat\Services\ConversationService;

/**
 * RAS-2 API18/API19 - last message, unread count, last activity,
 * typing status, online status.
 */
class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $otherMember = $this->members->firstWhere('user_id', '!=', $user->id);
        $otherUser = $otherMember?->user;
        $membership = $this->members->firstWhere('user_id', $user->id);

        $typingUsers = app(ConversationService::class)->activeTypingUsers($this->resource, $user);
        $presence = $otherUser ? $this->presenceFor($otherUser) : ['presence_status' => null, 'last_seen_at' => null];

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'type' => $this->type,
            'title' => $this->title,
            'is_archived' => $membership?->is_archived ?? $this->is_archived,
            'disappearing_messages_seconds' => $this->disappearing_messages_seconds,
            'last_message' => $this->whenLoaded('lastMessage', fn () => $this->lastMessage ? [
                'body' => $this->lastMessage->body,
                'message_type' => $this->lastMessage->message_type,
                'sender_id' => $this->lastMessage->sender_id,
                'created_at' => $this->lastMessage->created_at?->toISOString(),
                // The chat list preview needs these to decrypt an
                // end-to-end message client-side, the same way the
                // conversation thread itself does - without them, the
                // list has no way to tell "this is ciphertext, don't
                // just print it" from an ordinary plaintext message.
                'encryption_mode' => $this->lastMessage->encryption_mode,
                'iv' => $this->lastMessage->iv,
                'key_version' => $this->lastMessage->key_version,
            ] : null),
            'last_activity_at' => $this->last_activity_at?->toISOString(),
            'unread_count' => app(ConversationService::class)->unreadCount($this->resource, $user),
            'is_typing' => $typingUsers->isNotEmpty(),
            'other_user' => $otherUser ? [
                'uuid' => $otherUser->uuid,
                'full_name' => $otherUser->full_name,
                'profile_photo' => $otherUser->photoFor($request->user()),
                'presence_status' => $presence['presence_status'],
                'last_seen_at' => $presence['last_seen_at'],
            ] : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * Real bug fix: this previously ignored last_seen_visibility
     * entirely (unlike ContactResource, which already gated it
     * correctly) - a user who set last-seen to "nobody"/"contacts"
     * still leaked their live presence via the chat header/list. Also
     * applies the same Super Access fake_presence_mode override
     * ContactResource has (Documentation/SUPER.md): 'always_online'
     * reads live (no stored timestamp needed - the point is never
     * having to re-set one), 'custom_offline' is the original
     * fake_last_seen_at-at-a-fixed-time behavior.
     *
     * @return array{presence_status: ?string, last_seen_at: ?string}
     */
    private function presenceFor($otherUser): array
    {
        $privacy = $otherUser->privacy;

        if ($privacy?->last_seen_visibility === 'nobody') {
            return ['presence_status' => null, 'last_seen_at' => null];
        }

        if ($otherUser->one_view_super_access) {
            if ($privacy?->fake_presence_mode === 'always_online') {
                return ['presence_status' => 'online', 'last_seen_at' => now()->toISOString()];
            }
            if ($privacy?->fake_presence_mode === 'custom_offline' && $privacy->fake_last_seen_at) {
                return ['presence_status' => 'offline', 'last_seen_at' => $privacy->fake_last_seen_at->toISOString()];
            }
        }

        return [
            'presence_status' => $otherUser->presence?->presence_status,
            'last_seen_at' => $otherUser->presence?->last_seen_at?->toISOString(),
        ];
    }
}
