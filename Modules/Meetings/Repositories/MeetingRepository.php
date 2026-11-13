<?php

namespace Modules\Meetings\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Meetings\Models\Meeting;
use Modules\Meetings\Models\MeetingIceCandidate;
use Modules\Meetings\Models\MeetingLog;
use Modules\Meetings\Models\MeetingParticipant;
use Modules\Meetings\Models\MeetingSetting;
use Modules\Meetings\Models\MeetingSignal;
use Modules\Meetings\Models\MeetingWaitingRoom;

class MeetingRepository
{
    public function generateMeetingCode(): string
    {
        do {
            $code = (string) random_int(100000000, 999999999);
        } while (Meeting::where('meeting_code', $code)->exists());

        return $code;
    }

    public function createSettings(Meeting $meeting): MeetingSetting
    {
        return MeetingSetting::create(['meeting_id' => $meeting->id]);
    }

    public function activeParticipantCount(int $meetingId): int
    {
        return MeetingParticipant::where('meeting_id', $meetingId)->whereNull('left_at')->count();
    }

    public function findActiveParticipant(int $meetingId, int $userId): ?MeetingParticipant
    {
        return MeetingParticipant::where('meeting_id', $meetingId)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->first();
    }

    public function addParticipant(Meeting $meeting, ?int $userId, ?int $guestSessionId, string $role): MeetingParticipant
    {
        return MeetingParticipant::create([
            'meeting_id' => $meeting->id,
            'user_id' => $userId,
            'guest_session_id' => $guestSessionId,
            'role' => $role,
            'joined_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    public function addWaitingRoomEntry(Meeting $meeting, ?int $userId, ?string $guestName): MeetingWaitingRoom
    {
        return MeetingWaitingRoom::create([
            'meeting_id' => $meeting->id,
            'participant_type' => $userId ? 'user' : 'guest',
            'user_id' => $userId,
            'guest_name' => $guestName,
            'requested_at' => now(),
            'status' => 'waiting',
        ]);
    }

    public function waitingRoomFor(int $meetingId): Collection
    {
        return MeetingWaitingRoom::where('meeting_id', $meetingId)
            ->where('status', 'waiting')
            ->with('user')
            ->orderBy('requested_at')
            ->get();
    }

    public function myWaitingEntry(int $meetingId, int $userId): ?MeetingWaitingRoom
    {
        return MeetingWaitingRoom::where('meeting_id', $meetingId)
            ->where('user_id', $userId)
            ->latest('id')
            ->first();
    }

    public function log(Meeting $meeting, ?int $userId, string $event, array $context = []): void
    {
        MeetingLog::create([
            'meeting_id' => $meeting->id,
            'user_id' => $userId,
            'event' => $event,
            'context' => $context ?: null,
        ]);
    }

    public function createSignal(int $meetingId, int $senderParticipantId, int $receiverParticipantId, string $type, string $payload): MeetingSignal
    {
        return MeetingSignal::create([
            'meeting_id' => $meetingId,
            'sender_participant_id' => $senderParticipantId,
            'receiver_participant_id' => $receiverParticipantId,
            'signal_type' => $type,
            'payload' => $payload,
        ]);
    }

    public function createIceCandidate(int $meetingId, int $senderParticipantId, int $receiverParticipantId, string $candidate): MeetingIceCandidate
    {
        return MeetingIceCandidate::create([
            'meeting_id' => $meetingId,
            'sender_participant_id' => $senderParticipantId,
            'receiver_participant_id' => $receiverParticipantId,
            'candidate' => $candidate,
        ]);
    }

    public function signalsFor(int $participantId, int $afterId): Collection
    {
        return MeetingSignal::where('receiver_participant_id', $participantId)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->get();
    }

    public function iceCandidatesFor(int $participantId, int $afterId): Collection
    {
        return MeetingIceCandidate::where('receiver_participant_id', $participantId)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->get();
    }

    public function history(int $userId): Collection
    {
        return Meeting::whereHas('participants', fn ($q) => $q->where('user_id', $userId))
            ->whereIn('status', ['ended', 'cancelled'])
            ->with(['organizer', 'report'])
            ->orderByDesc('actual_start')
            ->get();
    }

    public function upcoming(int $userId): Collection
    {
        return Meeting::where(function ($q) use ($userId) {
            $q->where('organizer_id', $userId)
                ->orWhereHas('invitations', fn ($q2) => $q2->where('invited_user_id', $userId));
        })
            ->where('meeting_type', 'scheduled')
            ->whereIn('status', ['scheduled', 'waiting', 'active', 'locked'])
            ->with('organizer')
            ->orderBy('scheduled_start')
            ->get();
    }
}
