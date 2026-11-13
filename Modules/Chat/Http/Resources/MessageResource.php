<?php

namespace Modules\Chat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'conversation_id' => $this->conversation_id,
            'sender_id' => $this->sender_id,
            'sender' => [
                'uuid' => $this->sender?->uuid,
                'full_name' => $this->sender?->full_name,
                'profile_photo' => $this->sender?->photoFor($request->user()),
            ],
            'message_type' => $this->message_type,
            'body' => $this->body,
            'encryption_mode' => $this->encryption_mode,
            'key_version' => $this->key_version,
            'iv' => $this->iv,
            'media' => $this->whenLoaded('media', fn () => $this->media ? [
                'id' => $this->media->id,
                'original_filename' => $this->media->original_filename,
                'mime_type' => $this->media->mime_type,
                'media_type' => $this->media->media_type,
                'is_voice_note' => $this->media->is_voice_note,
                'duration_seconds' => $this->media->duration_seconds,
                'url' => url('/api/v1/media/'.$this->media->id),
            ] : null),
            'one_view_consumed' => $this->message_type === 'one_view'
                ? ($this->whenLoaded('oneViewMedia', fn () => $this->oneViewMedia?->isConsumed() ?? false) ?: false)
                : null,
            'status' => $this->status,
            // WhatsApp-style tick status - only meaningful (and only
            // ever sent) to the sender's own view; a recipient has no
            // business seeing this about their own copy of the message.
            'delivery_status' => $user && $this->sender_id === $user->id && $this->relationLoaded('statuses')
                ? $this->aggregateDeliveryStatus()
                : null,
            'reply_to' => $this->whenLoaded('replyMeta', fn () => $this->replyMeta ? [
                'message_id' => $this->replyMeta->replied_to_message_id,
                'sender_id' => $this->replyMeta->replied_to_sender_id,
                'type' => $this->replyMeta->replied_to_type,
                'preview' => $this->replyMeta->replied_to_preview,
                'preview_iv' => $this->replyMeta->replied_to_preview_iv,
            ] : null),
            'reactions' => $this->whenLoaded('reactions', fn () => $this->reactions->map(fn ($r) => [
                'user_id' => $r->user_id,
                'emoji' => $r->emoji,
            ])),
            'is_starred' => $user ? $this->starredBy($user->id) : false,
            'is_pinned' => $this->isPinned(),
            'edited_at' => $this->edited_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
