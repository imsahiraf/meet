<?php

namespace Modules\Meetings\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'meeting_code' => $this->meeting_code,
            'title' => $this->title,
            'description' => $this->description,
            'meeting_type' => $this->meeting_type,
            'has_password' => $this->hasPassword(),
            'scheduled_start' => $this->scheduled_start?->toISOString(),
            'scheduled_end' => $this->scheduled_end?->toISOString(),
            'timezone' => $this->timezone,
            'actual_start' => $this->actual_start?->toISOString(),
            'actual_end' => $this->actual_end?->toISOString(),
            'guest_enabled' => $this->guest_enabled,
            'waiting_room_enabled' => $this->waiting_room_enabled,
            'status' => $this->status,
            'organizer' => [
                'id' => $this->organizer_id,
                'full_name' => $this->organizer?->full_name,
            ],
            'chat_conversation_id' => $this->chat?->conversation_id ?? $this->chat()->value('conversation_id'),
            'settings' => $this->whenLoaded('settings', fn () => [
                'chat_enabled' => $this->settings->chat_enabled,
                'whiteboard_enabled' => $this->settings->whiteboard_enabled,
                'screen_share_enabled' => $this->settings->screen_share_enabled,
                'whiteboard_permission' => $this->settings->whiteboard_permission,
                'screen_share_permission' => $this->settings->screen_share_permission,
            ]),
            'ice_servers' => config('meetings.ice_servers'),
            'signal_poll_ms' => config('meetings.signal_poll_ms'),
            'incoming_call_poll_ms' => config('meetings.incoming_call_poll_ms'),
        ];
    }
}
