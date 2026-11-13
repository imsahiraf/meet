<?php

namespace Modules\Calls\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Modules\Authentication\Models\User;
use Modules\Calls\Exceptions\CallNotActiveException;
use Modules\Calls\Exceptions\RecipientBusyException;
use Modules\Calls\Models\Call;
use Modules\Calls\Models\CallParticipant;
use Modules\Calls\Repositories\CallRepository;
use Modules\Chat\Models\ConversationMember;
use Modules\Chat\Repositories\MessageRepository;
use Modules\Core\Services\PushNotificationService;

class CallService
{
    /**
     * SRS FR-087/FR-102 - default 30s ring timeout before a call is
     * auto-marked missed (see Jobs/MarkMissedCallsJob). Reads from
     * config rather than a hardcoded constant since the docs describe
     * this as admin-configurable.
     */
    public static function ringTimeoutSeconds(): int
    {
        return (int) config('calls.ring_timeout_seconds', 30);
    }

    public function __construct(
        private readonly CallRepository $calls,
        private readonly PushNotificationService $push,
        private readonly MessageRepository $messages,
    ) {
    }

    /**
     * FR-086/FR-101 - start a voice/video call. A call always STARTS as
     * 1:1 (one caller, one person being rung) and grows from there via
     * addParticipant() once it's connected - so this is unchanged in
     * shape, it just also creates the two call_participants rows that
     * are now the real membership list. FR-091 Busy State: neither
     * party may already be in another call.
     */
    public function start(User $caller, int $receiverId, string $callType, ?int $conversationId): Call
    {
        if ($caller->id === $receiverId) {
            throw new InvalidArgumentException('You cannot call yourself.');
        }

        if ($this->calls->findActiveCallFor($caller->id)) {
            throw new RecipientBusyException('You are already on another call.');
        }

        // Call waiting (owner 2026-11-08): somebody in a connected call still gets the new call ringing (they can
        // decline, or end the current call and answer); somebody whose phone already rings is busy.
        if ($this->calls->hasRingingCallFor($receiverId)) {
            throw new RecipientBusyException();
        }

        // A client-supplied conversation_id is only ever attached (so
        // the call can later be logged into that chat's own timeline -
        // see logCallInConversation()) once the caller is actually
        // confirmed to be a member of it - otherwise it's silently
        // dropped rather than trusted outright, since nothing before
        // this validated that a caller couldn't hand in an arbitrary
        // conversation they don't belong to.
        if ($conversationId !== null && ! ConversationMember::where('conversation_id', $conversationId)->where('user_id', $caller->id)->exists()) {
            $conversationId = null;
        }

        $call = $this->calls->create($caller->id, $receiverId, $callType, $conversationId);

        $this->sendRingingPush($call);

        return $call;
    }

    /**
     * `renotify: true` + a stable `tag: 'call-'.$call->id` are already
     * set on the call notification in public/service-worker.js -
     * exactly what would re-trigger the OS's own native notification
     * sound each time a new push with that same tag arrives, the same
     * way a real incoming call keeps ringing rather than buzzing once.
     * The only piece that was ever missing is a SECOND push - this had
     * only ever fired once, at the moment ringing started. See
     * resendRingingPushIfDue() for where the repeat comes from.
     */
    private function sendRingingPush(Call $call): void
    {
        $receiver = $call->receiver;
        if (! ($receiver?->preferences?->notify_calls ?? true)) {
            return;
        }

        // call_type/caller_id ride along so the service worker can
        // offer real Accept/Decline actions straight from the
        // notification shade (see public/service-worker.js) instead
        // of a name and "Calling…" with nothing to act on.
        $this->push->sendToUser($receiver, $call->caller->full_name, ucfirst($call->call_type).' call', [
            'type' => 'call',
            'call_id' => $call->id,
            'caller_id' => $call->caller_id,
            'call_type' => $call->call_type,
            'conversation_id' => $call->conversation_id,
        ]);
    }

    /**
     * There's no persistent worker on this host to run a real "repeat
     * every 2-3s for the ~30s ring window" job (see PushNotificationService's
     * own docblock on why everything here runs synchronously inline
     * instead) - but the caller's own client already polls
     * CallController::show() every ~2s for the entire ring window
     * (pollCallStatus() in app.blade.php), purely to render the
     * "Calling…" screen. Piggybacking the repeat push on that existing,
     * already-guaranteed-to-recur request needs no new
     * scheduling/queueing infrastructure at all. Cache::add() is an
     * atomic "only if not already set" - the real rate limit here,
     * since the caller's poll interval is client-configurable
     * (CALLS_INCOMING_POLL_MS) and nothing stops multiple tabs/a
     * flakier interval from hitting this more often than intended.
     */
    public function resendRingingPushIfDue(Call $call): void
    {
        if ($call->call_status !== 'ringing') {
            return;
        }

        if (! Cache::add('call-ring-push:'.$call->id, true, now()->addSeconds(2))) {
            return;
        }

        $this->sendRingingPush($call);
    }

    /**
     * FR-088 - the original invitee only, while still ringing. Someone
     * added to an already-connected call never goes through this: they
     * are joined directly by addParticipant() (see its docblock).
     */
    public function accept(Call $call, User $user): Call
    {
        $this->assertPresentParticipant($call, $user);

        if ($call->receiver_id !== $user->id) {
            throw new AuthorizationException('Only the recipient may accept this call.');
        }

        if ($call->call_status !== 'ringing') {
            throw new CallNotActiveException();
        }

        // Answering a waiting call ends your part in the call you were on (the "end and answer" choice).
        foreach ($this->calls->otherAcceptedCallsFor($user->id, $call->id) as $other) {
            $this->end($other, $user);
        }

        $call->forceFill(['call_status' => 'accepted', 'answered_at' => now()])->save();
        $this->calls->markParticipantJoined($call, $user->id);

        return $call;
    }

    /**
     * FR-089 - the original invitee only, while still ringing.
     */
    public function reject(Call $call, User $user): Call
    {
        $this->assertPresentParticipant($call, $user);

        if ($call->receiver_id !== $user->id) {
            throw new AuthorizationException('Only the recipient may reject this call.');
        }

        if ($call->call_status !== 'ringing') {
            throw new CallNotActiveException();
        }

        $call->forceFill(['call_status' => 'rejected', 'ended_at' => now()])->save();
        $this->calls->markAllParticipantsLeft($call);
        $this->calls->recordHistory($call, 'rejected');
        $this->logCallInConversation($call);

        return $call;
    }

    /**
     * Hanging up. With N people this is "leave", not necessarily "end
     * for everyone": one person dropping out of a four-way call must
     * leave the other three talking. The call itself only reaches a
     * terminal state once fewer than two people are left in it, at which
     * point there's nobody for the last person to talk to anyway.
     *
     * The ringing cases are unchanged (only the caller may cancel a
     * still-ringing call; the invitee rejects instead).
     */
    public function end(Call $call, User $user): Call
    {
        $this->assertPresentParticipant($call, $user);

        if ($call->call_status === 'ringing') {
            if ($call->caller_id !== $user->id) {
                throw new AuthorizationException('Only the caller may cancel a ringing call.');
            }

            $call->forceFill(['call_status' => 'cancelled', 'ended_at' => now()])->save();
            $this->calls->markAllParticipantsLeft($call);
            $this->calls->recordHistory($call, 'cancelled');
            $this->logCallInConversation($call);

            return $call;
        }

        if ($call->call_status !== 'accepted') {
            throw new CallNotActiveException();
        }

        $this->calls->markParticipantLeft($call, $user->id);

        if ($call->activeParticipants()->count() >= 2) {
            // Still a live call for everyone else - their own polls see
            // this person disappear from the participant list and tear
            // down just that one peer connection.
            return $call;
        }

        $endedAt = now();
        $duration = $call->answered_at ? $call->answered_at->diffInSeconds($endedAt) : 0;

        $call->forceFill([
            'call_status' => 'ended',
            'ended_at' => $endedAt,
            'duration_seconds' => $duration,
        ])->save();

        $this->calls->markAllParticipantsLeft($call);
        $this->calls->recordHistory($call, 'completed');
        $this->logCallInConversation($call);

        return $call;
    }

    /**
     * "Add member" during a live call. This used to end the call and
     * escalate everyone into a brand-new Meeting (escalateToMeeting(),
     * now gone along with calls.escalated_to_meeting_id) - the call is
     * natively N-party now, so adding someone is just one more
     * participant row on the SAME call. Nobody navigates anywhere: the
     * existing polls make the new person appear as another tile in
     * everyone's grid, and the new person's own incoming-call poll picks
     * the call up and joins them straight into it.
     *
     * They are joined directly rather than rung, mirroring how the
     * Meetings module admits someone pulled into a call in progress -
     * there's no "waiting to be let in" moment to model when an existing
     * participant has explicitly chosen to bring them in.
     */
    public function addParticipant(Call $call, User $actor, User $newUser): Call
    {
        $this->assertActiveParticipant($call, $actor);

        if ($call->call_status !== 'accepted') {
            throw new CallNotActiveException('You can only add people once the call has connected.');
        }

        // Already in it - idempotent rather than a second tile for the
        // same human (the unique index would reject it anyway).
        if ($call->hasParticipant($newUser->id)) {
            return $call;
        }

        // FR-091 Busy State applies to being added just as much as to
        // being called: you cannot be pulled into a second live call.
        $existing = $this->calls->findActiveCallFor($newUser->id);
        if ($existing && $existing->id !== $call->id) {
            throw new RecipientBusyException();
        }

        $this->calls->addParticipant($call, $newUser->id, CallParticipant::PARTICIPANT, now());

        if ($newUser->preferences?->notify_calls ?? true) {
            $this->push->sendToUser($newUser, $actor->full_name, 'Added you to a '.$call->call_type.' call', [
                'type' => 'call',
                'call_id' => $call->id,
                'caller_id' => $actor->id,
                'call_type' => $call->call_type,
                'conversation_id' => $call->conversation_id,
            ]);
        }

        return $call->load(['caller', 'receiver', 'participants.user']);
    }

    /**
     * WhatsApp-style: the call gets its own centered "system" row in the
     * chat timeline it was started from, right alongside the regular
     * messages - "Voice call · 3:24" for one that connected, "Missed
     * voice/video call" for anything that never did (rejected,
     * cancelled before pickup, or timed out - MarkMissedCallsJob calls
     * this too). A call that grew past two people says so, since
     * "Voice call · 3:24" reads wrong for what was actually a group
     * call. Silently does nothing if the call has no conversation_id.
     */
    public function logCallInConversation(Call $call): void
    {
        if (! $call->conversation_id) {
            return;
        }

        $conversation = $call->conversation;
        $caller = $call->caller;

        if (! $conversation || ! $caller) {
            return;
        }

        $everJoined = $call->participants->whereNotNull('joined_at')->count();
        $label = $call->call_type === 'video' ? 'Video call' : 'Voice call';
        $groupLabel = $everJoined > 2 ? 'Group '.strtolower($label) : $label;

        $body = match ($call->call_status) {
            'ended' => $call->duration_seconds
                ? $groupLabel.' · '.gmdate('i:s', $call->duration_seconds)
                : $groupLabel,
            'rejected' => 'Declined '.strtolower($label),
            'cancelled' => $label.' cancelled',
            default => 'Missed '.strtolower($label), // 'missed' (MarkMissedCallsJob) and any other terminal state
        };

        $this->messages->create($conversation, $caller, ['message_type' => 'system', 'body' => $body]);
    }

    /**
     * Offer/answer/ICE are always between one specific PAIR inside the
     * call, so the sender says who the signal is for - exactly how
     * MeetingSignalingController scopes its mesh signaling per
     * participant pair. (Meetings keys those by meeting_participants.id
     * because a guest has no user id; a call has no guests, so a plain
     * user id is the natural key here.)
     */
    public function sendOffer(Call $call, User $sender, int $receiverId, string $sdp): void
    {
        $this->assertPairedParticipants($call, $sender, $receiverId);
        $this->calls->createSignal($call->id, $sender->id, $receiverId, 'offer', $sdp);
    }

    public function sendAnswer(Call $call, User $sender, int $receiverId, string $sdp): void
    {
        $this->assertPairedParticipants($call, $sender, $receiverId);
        $this->calls->createSignal($call->id, $sender->id, $receiverId, 'answer', $sdp);
    }

    public function sendIceCandidate(Call $call, User $sender, int $receiverId, string $candidate): void
    {
        $this->assertPairedParticipants($call, $sender, $receiverId);
        $this->calls->createIceCandidate($call->id, $sender->id, $receiverId, $candidate);
    }

    /**
     * A quick emoji reaction during a call (Meet/Zoom-style) - reuses
     * the same generic signal channel offer/answer already ride on.
     * Unlike offer/answer/ICE this is not pair-specific: a reaction is
     * for the room, so it fans out one row per other participant (which
     * for a 1:1 call is exactly the single row it always was).
     */
    public function sendReaction(Call $call, User $sender, string $emoji): void
    {
        $this->fanOutSignal($call, $sender, 'reaction', $emoji);
    }

    /**
     * Screen-share start/stop - explicit application-level signal (not
     * just "a video track showed up"), since every peer connection
     * pre-provisions its video transceiver with no track from the start
     * specifically so this never needs SDP renegotiation; the receiving
     * side has no other reliable way to tell "camera turned on" apart
     * from "screen share started/stopped", or when either one stops.
     * Fanned out to everyone, same as a reaction.
     */
    public function sendScreenShareSignal(Call $call, User $sender, string $state): void
    {
        $this->fanOutSignal($call, $sender, 'screen-share', $state);
    }

    /**
     * In-call event for everyone else in the call: captions, polls, votes, questions, "my video is on",
     * "send me less data". The payload is small JSON {kind, data}; only known kinds are allowed.
     */
    public const EVENT_KINDS = ['caption', 'poll', 'vote', 'question', 'upvote', 'video', 'low-data'];

    public function sendEvent(Call $call, User $sender, string $kind, array $data): void
    {
        $payload = json_encode(['kind' => $kind, 'data' => $data], JSON_UNESCAPED_UNICODE);
        if (! in_array($kind, self::EVENT_KINDS, true) || $payload === false || strlen($payload) > 4000) {
            throw new \InvalidArgumentException('Unknown or too large call event.');
        }
        $this->fanOutSignal($call, $sender, 'event', $payload);
    }

    private function fanOutSignal(Call $call, User $sender, string $type, string $payload): void
    {
        $this->assertActiveParticipant($call, $sender);

        foreach ($call->otherActiveParticipantIds($sender->id) as $receiverId) {
            $this->calls->createSignal($call->id, $sender->id, $receiverId, $type, $payload);
        }
    }

    public function pollSignals(Call $call, User $user, int $afterSignalId, int $afterIceId): array
    {
        $this->assertParticipant($call, $user);

        return [
            'signals' => $this->calls->signalsFor($call->id, $user->id, $afterSignalId),
            'ice_candidates' => $this->calls->iceCandidatesFor($call->id, $user->id, $afterIceId),
        ];
    }

    public function incomingCallFor(User $user): ?Call
    {
        return $this->calls->findIncomingCallFor($user->id);
    }

    public function history(User $user, ?string $status = null, ?int $withUserId = null)
    {
        return $this->calls->history($user->id, $status, $withUserId);
    }

    public function authorizeParticipant(Call $call, User $user): void
    {
        $this->assertParticipant($call, $user);
    }

    /**
     * Real WhatsApp-style mute/camera-off signal to everyone else -
     * stored on this person's own participant row (it used to be a JSON
     * blob keyed by 'caller'/'receiver', which cannot describe a third
     * person). Polled the same way call_status transitions already are
     * (show()/pollCallStatus()), not a new channel.
     */
    public function updateMedia(Call $call, User $user, ?bool $audioEnabled, ?bool $videoEnabled): Call
    {
        $this->assertPresentParticipant($call, $user);
        $this->calls->updateParticipantMedia($call, $user->id, $audioEnabled, $videoEnabled);

        return $call;
    }

    /**
     * READ access: anyone who has a participant row for this call, even
     * one they've already left. Deliberately looser than
     * assertPresentParticipant() below, because leaving a call is exactly
     * when a client most needs to read it - the only way the other side
     * learns a call ended is its own status poll, and a poll already in
     * flight at the moment the call ends must not come back 403 (it did,
     * briefly, when this checked live presence: real browser runs showed
     * a burst of 403s on every hangup). A stranger still gets 403.
     */
    private function assertParticipant(Call $call, User $user): void
    {
        if ($call->participantFor($user->id) === null) {
            throw new AuthorizationException('You are not a participant in this call.');
        }
    }

    /**
     * WRITE access: you must still actually be in the call to act on it -
     * accept/reject/hang up/change your media state. Somebody who already
     * left can read the call (above) but not touch it.
     */
    private function assertPresentParticipant(Call $call, User $user): void
    {
        if (! $call->hasParticipant($user->id)) {
            throw new AuthorizationException('You are not a participant in this call.');
        }
    }

    private function assertActiveParticipant(Call $call, User $user): void
    {
        $this->assertPresentParticipant($call, $user);

        if (! $call->isActive()) {
            throw new CallNotActiveException();
        }
    }

    /**
     * Both ends of a signaling pair have to really be in this call -
     * otherwise a participant could use the call as a relay to push
     * arbitrary SDP/ICE at any user id they liked.
     */
    private function assertPairedParticipants(Call $call, User $sender, int $receiverId): void
    {
        $this->assertActiveParticipant($call, $sender);

        if ($receiverId === $sender->id || ! in_array($receiverId, $call->otherActiveParticipantIds($sender->id), true)) {
            throw new AuthorizationException('That user is not a participant in this call.');
        }
    }
}
