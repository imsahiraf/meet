<?php

namespace Modules\Calls\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Calls\Models\CallParticipant;

class CallResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $userId = $request->user()?->id;
        $participants = $this->activeParticipants();
        $others = $participants->reject(fn (CallParticipant $p) => $p->user_id === $userId)->values();
        $me = $userId ? $this->participantFor($userId) : null;

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'call_type' => $this->call_type,
            'call_status' => $this->call_status,
            'conversation_id' => $this->conversation_id,
            'is_caller' => $userId === $this->caller_id,
            // Who started it / who it was originally started toward -
            // display metadata only; `participants` below is the real
            // membership list (see the Call model's docblock).
            'caller' => [
                'id' => $this->caller_id,
                'full_name' => $this->caller?->full_name,
                'profile_photo' => $this->caller?->photoFor($request->user()),
            ],
            'receiver' => [
                'id' => $this->receiver_id,
                'full_name' => $this->receiver?->full_name,
                'profile_photo' => $this->receiver?->photoFor($request->user()),
            ],
            // The N-party payload the client's video grid is built from:
            // one entry per person actually in the call right now, each
            // with their own mute/camera state. Replaces the single
            // caller/receiver pair as the source of truth for the UI.
            'participants' => $participants->map(fn (CallParticipant $p) => [
                'user_id' => $p->user_id,
                'full_name' => $p->user?->full_name,
                'profile_photo' => $p->user?->photoFor($request->user()),
                'role' => $p->role,
                'is_me' => $p->user_id === $userId,
                'audio_enabled' => (bool) $p->audio_enabled,
                'video_enabled' => (bool) $p->video_enabled,
                'joined_at' => $p->joined_at?->toISOString(),
            ])->all(),
            'participant_count' => $participants->count(),
            // Lets the client tell "ring me" (I'm the invitee on a still-
            // ringing call) from "join me straight in" (I was added to a
            // call already in progress) without inferring either from
            // caller_id/receiver_id.
            'me' => $me ? [
                'user_id' => $me->user_id,
                'role' => $me->role,
                'joined_at' => $me->joined_at?->toISOString(),
                'audio_enabled' => (bool) $me->audio_enabled,
                'video_enabled' => (bool) $me->video_enabled,
            ] : null,
            'started_at' => $this->started_at?->toISOString(),
            'answered_at' => $this->answered_at?->toISOString(),
            'ended_at' => $this->ended_at?->toISOString(),
            'duration_seconds' => $this->duration_seconds,
            'ring_timeout_seconds' => \Modules\Calls\Services\CallService::ringTimeoutSeconds(),
            // Retained convenience for the N=2 case (still the common
            // one): the single other party's mute/camera state. Null as
            // soon as there is more than one other person, at which point
            // "the other party" isn't a meaningful thing and the client
            // reads per-person state from `participants` instead.
            'other_party_media_state' => $others->count() === 1
                ? $this->mediaStateFor($others->first()->user_id)
                : null,
        ];
    }
}
