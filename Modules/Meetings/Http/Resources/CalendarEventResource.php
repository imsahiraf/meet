<?php

namespace Modules\Meetings\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CalendarEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'event_type' => $this->event_type,
            'title' => $this->title,
            'description' => $this->description,
            'start_at' => $this->start_at->toISOString(),
            'end_at' => $this->end_at->toISOString(),
            'timezone' => $this->timezone,
            'meeting_id' => $this->meeting_id,
            'meeting_code' => $this->meeting_id ? $this->whenLoaded('meeting', fn () => $this->meeting?->meeting_code) : null,
            'status' => $this->status,
            'my_rsvp' => $this->whenLoaded('rsvps', function () use ($request) {
                $mine = $this->rsvps->firstWhere('user_id', $request->user()?->id);

                return $mine?->status;
            }),
        ];
    }
}
