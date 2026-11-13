<?php

namespace Modules\Calls\Repositories;

use Illuminate\Support\Collection;
use Modules\Calls\Models\Call;
use Modules\Calls\Models\CallHistory;
use Modules\Calls\Models\CallParticipant;
use Modules\Calls\Models\IceCandidate;
use Modules\Calls\Models\SignalingMessage;
use Modules\Calls\Services\CallService;

class CallRepository
{
    /**
     * Standard eager-load set for anything that will read membership -
     * with N participants, letting `participants` lazy-load per access
     * turns one query into one-per-person-per-call-site.
     */
    private const WITH = ['caller', 'receiver', 'participants.user'];

    /**
     * FR-091 Busy State, N-aware: being in a 3-way call makes you just
     * as busy as being in a 1:1 one, so this asks call_participants
     * (the authoritative membership list) rather than matching
     * caller_id/receiver_id, which would miss anyone who joined via
     * "Add member".
     *
     * present() rather than active() on purpose: a receiver who is
     * currently being rung and hasn't answered yet is already
     * committed to this call and must not be rung by someone else at
     * the same time.
     */
    public function findActiveCallFor(int $userId): ?Call
    {
        return Call::with(self::WITH)
            ->whereIn('call_status', ['ringing', 'accepted'])
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userId)->present())
            ->first();
    }

    /**
     * Call waiting: a person in a connected call may be rung once more, but never while any call of theirs rings.
     */
    public function hasRingingCallFor(int $userId): bool
    {
        return Call::where('call_status', 'ringing')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userId)->present())
            ->exists();
    }

    /** Other connected calls this person is still in (left when they answer a waiting call). */
    public function otherAcceptedCallsFor(int $userId, int $exceptCallId)
    {
        return Call::with(self::WITH)
            ->where('call_status', 'accepted')
            ->where('id', '!=', $exceptCallId)
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userId)->present())
            ->get();
    }

    /**
     * "Is there a call I should be seeing right now?" - two distinct
     * cases the client tells apart by call_status:
     *   - 'ringing': I'm the original receiver, ring my device.
     *   - 'accepted': I was added to a call already in progress by
     *     someone tapping "Add member" (see CallService::addParticipant)
     *     - join straight into it, no ringing, no navigation.
     * Ordered newest-first so a freshly-added membership wins over a
     * stale one.
     */
    public function findIncomingCallFor(int $userId): ?Call
    {
        return Call::with(self::WITH)
            ->whereIn('call_status', ['ringing', 'accepted'])
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userId)->present())
            ->latest('id')
            ->get()
            ->first(function (Call $call) use ($userId) {
                $participant = $call->participantFor($userId);
                if (! $participant || $participant->left_at !== null) {
                    return false;
                }

                // Ringing: only the invitee should see a ring, never the
                // caller (whose own UI is already showing "Calling…").
                if ($call->call_status === 'ringing') {
                    return $call->receiver_id === $userId;
                }

                // Accepted: somebody who has actually been joined into it
                // (added mid-call) needs picking up here - but only for a
                // bounded window after being added. Without that bound,
                // ANY still-open accepted call this user has a membership
                // row for would pull their next page load into it, so one
                // stale row (a browser that vanished mid-call, leaving the
                // call non-terminal until the cron sweeps it) would drag
                // them into a ghost call on every page they opened. Two
                // ring timeouts is the same "nobody picked this up in
                // time" window a ringing call already gets.
                return $participant->joined_at !== null
                    && $participant->joined_at->gt(now()->subSeconds(2 * CallService::ringTimeoutSeconds()));
            });
    }

    public function create(int $callerId, int $receiverId, string $callType, ?int $conversationId): Call
    {
        $call = Call::create([
            'caller_id' => $callerId,
            'receiver_id' => $receiverId,
            'conversation_id' => $conversationId,
            'call_type' => $callType,
            'call_status' => 'ringing',
            'started_at' => now(),
        ]);

        // Both parties get a membership row up front. The caller is in
        // it immediately (they're the one placing it); the receiver is
        // invited but not joined until they accept - which is exactly
        // what joined_at being null means.
        $this->addParticipant($call, $callerId, CallParticipant::CALLER, now());
        $this->addParticipant($call, $receiverId, CallParticipant::PARTICIPANT, null);

        return $call->load(self::WITH);
    }

    public function addParticipant(Call $call, int $userId, string $role = CallParticipant::PARTICIPANT, $joinedAt = null): CallParticipant
    {
        $participant = CallParticipant::updateOrCreate(
            ['call_id' => $call->id, 'user_id' => $userId],
            ['role' => $role, 'joined_at' => $joinedAt, 'left_at' => null],
        );

        // The in-memory relation must reflect the new row immediately -
        // the caller of this typically turns straight around and asks
        // the Call who's in it (to fan a signal out, or to build the
        // API response), and a stale loaded relation would silently
        // leave the new person out.
        $call->unsetRelation('participants');

        return $participant;
    }

    public function markParticipantJoined(Call $call, int $userId): void
    {
        CallParticipant::where('call_id', $call->id)->where('user_id', $userId)
            ->update(['joined_at' => now(), 'left_at' => null, 'updated_at' => now()]);
        $call->unsetRelation('participants');
    }

    public function markParticipantLeft(Call $call, int $userId): void
    {
        CallParticipant::where('call_id', $call->id)->where('user_id', $userId)->whereNull('left_at')
            ->update(['left_at' => now(), 'updated_at' => now()]);
        $call->unsetRelation('participants');
    }

    /**
     * Nobody is left in a call once it reaches a terminal state - without
     * this, a rejected/cancelled/missed call would leave its rows looking
     * like people are still sitting in it, and findActiveCallFor() above
     * would report them permanently busy.
     */
    public function markAllParticipantsLeft(Call $call): void
    {
        CallParticipant::where('call_id', $call->id)->whereNull('left_at')
            ->update(['left_at' => now(), 'updated_at' => now()]);
        $call->unsetRelation('participants');
    }

    public function updateParticipantMedia(Call $call, int $userId, ?bool $audioEnabled, ?bool $videoEnabled): void
    {
        $participant = CallParticipant::where('call_id', $call->id)->where('user_id', $userId)->first();

        if (! $participant) {
            return;
        }

        $participant->forceFill([
            'audio_enabled' => $audioEnabled ?? $participant->audio_enabled,
            'video_enabled' => $videoEnabled ?? $participant->video_enabled,
        ])->save();

        $call->unsetRelation('participants');
    }

    /**
     * participant_count is everyone who was ever really in it (joined at
     * some point), not just whoever happened to still be there at the
     * final hangup - a 4-way call that dwindled to 2 before ending was
     * still a 4-way call in the history list.
     */
    public function recordHistory(Call $call, string $endReason): CallHistory
    {
        $everJoined = CallParticipant::where('call_id', $call->id)->whereNotNull('joined_at')->count();

        return CallHistory::create([
            'call_id' => $call->id,
            'caller_id' => $call->caller_id,
            'receiver_id' => $call->receiver_id,
            'participant_count' => max(2, $everJoined),
            'duration_seconds' => $call->duration_seconds ?? 0,
            'end_reason' => $endReason,
        ]);
    }

    /**
     * History is per-participant now: somebody added to a call mid-way
     * through must see it in their own history too, which a
     * caller_id/receiver_id match could never surface. The
     * with_user_id filter ("calls with this contact") is likewise a
     * membership question, not a pair-match.
     */
    public function history(int $userId, ?string $status = null, ?int $withUserId = null): Collection
    {
        return Call::with(['caller', 'receiver', 'history', 'participants.user'])
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userId))
            ->whereIn('call_status', ['ended', 'rejected', 'missed', 'cancelled'])
            ->when($status, fn ($q) => $q->where('call_status', $status))
            ->when($withUserId, fn ($q) => $q->whereHas('participants', fn ($q2) => $q2->where('user_id', $withUserId)))
            ->orderByDesc('started_at')
            ->get();
    }

    public function createSignal(int $callId, int $senderId, int $receiverId, string $type, string $payload): SignalingMessage
    {
        return SignalingMessage::create([
            'call_id' => $callId,
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'signal_type' => $type,
            'payload' => $payload,
        ]);
    }

    public function createIceCandidate(int $callId, int $senderId, int $receiverId, string $candidate): IceCandidate
    {
        return IceCandidate::create([
            'call_id' => $callId,
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'candidate' => $candidate,
        ]);
    }

    public function signalsFor(int $callId, int $userId, int $afterId): Collection
    {
        return SignalingMessage::where('call_id', $callId)
            ->where('receiver_id', $userId)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->get();
    }

    public function iceCandidatesFor(int $callId, int $userId, int $afterId): Collection
    {
        return IceCandidate::where('call_id', $callId)
            ->where('receiver_id', $userId)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->get();
    }
}
