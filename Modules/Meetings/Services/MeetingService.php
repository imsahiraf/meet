<?php

namespace Modules\Meetings\Services;

use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\ConversationMember;
use Modules\Meetings\Exceptions\GuestAccessDisabledException;
use Modules\Meetings\Exceptions\GuestSessionExpiredException;
use Modules\Meetings\Exceptions\HostCannotBeRemovedException;
use Modules\Meetings\Exceptions\InvalidMeetingPasswordException;
use Modules\Meetings\Exceptions\MeetingLockedException;
use Modules\Meetings\Exceptions\MeetingNotActiveException;
use Modules\Meetings\Exceptions\ParticipantLimitReachedException;
use Modules\Meetings\Exceptions\ScreenShareInUseException;
use Modules\Meetings\Models\Meeting;
use Modules\Meetings\Models\MeetingChat;
use Modules\Meetings\Models\MeetingGuestSession;
use Modules\Meetings\Models\MeetingParticipant;
use Modules\Meetings\Models\MeetingReport;
use Modules\Meetings\Models\MeetingWaitingRoom;
use Modules\Meetings\Repositories\MeetingRepository;

class MeetingService
{
    public static function maxParticipants(): int
    {
        return (int) config('meetings.max_participants', 8);
    }

    public static function guestSessionMinutes(): int
    {
        return (int) config('meetings.guest_session_minutes', 15);
    }

    public static function staleParticipantSeconds(): int
    {
        return (int) config('meetings.stale_participant_seconds', 30);
    }

    public function __construct(
        private readonly MeetingRepository $meetings,
    ) {
    }

    /**
     * FR-121 Instant Meeting. The host is NOT auto-added as a
     * participant here - like everyone else, they go through the room
     * page's own lobby first (device preview, camera/mic permission
     * prompt, explicit "Join meeting" click - see
     * MeetingWebController::show()'s alreadyJoined check and join()
     * below), same as real Google Meet. Auto-joining immediately at
     * creation time used to make the host's very own first-ever page
     * load indistinguishable from "refreshed mid-call, rejoin
     * automatically" - dropping them straight into the room with no
     * chance to grant/decline device access first.
     */
    public function createInstant(User $organizer, string $title): Meeting
    {
        $meeting = Meeting::create([
            'organizer_id' => $organizer->id,
            'meeting_code' => $this->meetings->generateMeetingCode(),
            'title' => $title,
            'meeting_type' => 'instant',
            'status' => 'active',
            'actual_start' => now(),
            'guest_enabled' => true,
            'waiting_room_enabled' => false,
        ]);

        $this->meetings->createSettings($meeting);
        $this->createMeetingChat($meeting);
        $this->meetings->log($meeting, $organizer->id, 'meeting_created');

        return $meeting;
    }

    /**
     * FR-122 Schedule Meeting - business rule: only verified users may
     * schedule (enforced by the controller/request via email_verified_at,
     * same pattern used for other verified-only actions in this app).
     */
    public function createScheduled(User $organizer, array $data): Meeting
    {
        // The web schedule form's <input type="datetime-local"> submits
        // a plain "wall clock" string with no UTC offset - Carbon's
        // default parse would silently interpret it as APP_TIMEZONE
        // (UTC) instead of the organizer's real timezone, quietly
        // shifting every scheduled meeting by the browser's UTC offset.
        // An already-offset-bearing ISO string (as sent by non-browser
        // API callers) is unaffected - Carbon::parse() respects an
        // explicit offset in the string over this second argument.
        // The trailing ->utc() is required too: Eloquent's plain
        // 'datetime' cast writes whatever wall-clock digits the Carbon
        // instance currently displays, in that instance's own
        // timezone - it does NOT normalize to UTC on save by itself.
        $meeting = Meeting::create([
            'organizer_id' => $organizer->id,
            'meeting_code' => $this->meetings->generateMeetingCode(),
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'meeting_type' => 'scheduled',
            'password' => filled($data['password'] ?? null) ? Hash::make($data['password']) : null,
            'scheduled_start' => Carbon::parse($data['scheduled_start'], $data['timezone'])->utc(),
            'scheduled_end' => Carbon::parse($data['scheduled_end'], $data['timezone'])->utc(),
            'timezone' => $data['timezone'],
            'guest_enabled' => $data['guest_enabled'] ?? true,
            'waiting_room_enabled' => $data['waiting_room_enabled'] ?? true,
            'status' => 'scheduled',
        ]);

        $this->meetings->createSettings($meeting);
        $this->createMeetingChat($meeting);
        $this->meetings->log($meeting, $organizer->id, 'meeting_created');

        return $meeting;
    }

    /**
     * Guest-created meeting (no login) - the creator becomes the
     * meeting's de-facto host (role=host on their participant row)
     * but, being unauthenticated, cannot use the authenticated-only
     * host-control endpoints (mute/remove/lock/transfer - all keyed
     * on a real User actor). The meeting winds down naturally via
     * guest-session expiry rather than an explicit "host ends it"
     * action, same as any other guest session.
     */
    public function guestCreateInstant(string $guestName, ?string $title, ?string $password): array
    {
        $meeting = Meeting::create([
            'organizer_id' => null,
            'meeting_code' => $this->meetings->generateMeetingCode(),
            'title' => $title ?: 'Guest meeting',
            'meeting_type' => 'instant',
            'status' => 'active',
            'actual_start' => now(),
            'password' => filled($password) ? Hash::make($password) : null,
            'guest_enabled' => true,
            'waiting_room_enabled' => false,
        ]);

        $this->meetings->createSettings($meeting);
        // No createMeetingChat() here - the in-meeting text-chat
        // conversation requires real User ConversationMember rows,
        // which guests can never be; the guest room UI has no chat
        // tab at all, so there's nothing for it to back.

        $session = $this->activateGuestSession($meeting, $guestName, MeetingParticipant::HOST);

        return ['meeting' => $meeting, 'guestSession' => $session];
    }

    public function cancel(Meeting $meeting, User $actor): Meeting
    {
        if ($meeting->organizer_id !== $actor->id) {
            throw new AuthorizationException('Only the organizer may cancel this meeting.');
        }

        $meeting->forceFill(['status' => 'cancelled'])->save();
        $this->meetings->log($meeting, $actor->id, 'meeting_cancelled');

        return $meeting;
    }

    private function createMeetingChat(Meeting $meeting): void
    {
        $conversation = Conversation::create([
            'type' => 'meeting',
            'title' => $meeting->title,
            'created_by' => $meeting->organizer_id,
            'last_activity_at' => now(),
        ]);

        MeetingChat::create(['meeting_id' => $meeting->id, 'conversation_id' => $conversation->id]);
    }

    private function addConversationMember(Meeting $meeting, int $userId): void
    {
        $chat = $meeting->chat ?? $meeting->chat()->first();

        if (! $chat) {
            return;
        }

        ConversationMember::firstOrCreate(
            ['conversation_id' => $chat->conversation_id, 'user_id' => $userId],
            ['joined_at' => now(), 'role' => 'member'],
        );
    }

    /**
     * FR-125 Join Meeting. Returns a MeetingParticipant when the user is
     * admitted immediately, or a MeetingWaitingRoom entry when FR-127's
     * waiting room holds them for host approval.
     */
    /**
     * Directly admits a specific user, bypassing the lobby/waiting-room
     * flow entirely - for a caller escalating an existing 1:1 call into
     * a meeting, where both parties are already mutually engaged in a
     * live call, there's no real "waiting to be let in" moment to model.
     */
    public function addDirectParticipant(Meeting $meeting, User $user, string $role = MeetingParticipant::PARTICIPANT): MeetingParticipant
    {
        if ($existing = $this->meetings->findActiveParticipant($meeting->id, $user->id)) {
            return $existing;
        }

        $participant = $this->meetings->addParticipant($meeting, $user->id, null, $role);
        $this->addConversationMember($meeting, $user->id);

        return $participant;
    }

    /**
     * "Am I already in this meeting right now" - lets the room page
     * skip straight past the lobby/join-button screen on a refresh for
     * someone who never actually left, rather than re-prompting for
     * camera/mic permission and an explicit "Join meeting" click every
     * time the tab reloads. join() itself already handles this
     * idempotently server-side (see the findActiveParticipant() check
     * above) - this just lets the page know to call it automatically.
     */
    public function activeParticipantFor(Meeting $meeting, User $user): ?MeetingParticipant
    {
        return $this->meetings->findActiveParticipant($meeting->id, $user->id);
    }

    public function join(Meeting $meeting, User $user, ?string $password = null): MeetingParticipant|MeetingWaitingRoom
    {
        $this->assertJoinable($meeting, $password);

        if ($existing = $this->meetings->findActiveParticipant($meeting->id, $user->id)) {
            return $existing;
        }

        $isOrganizer = $meeting->organizer_id === $user->id;

        if (! $isOrganizer && $this->meetings->activeParticipantCount($meeting->id) >= self::maxParticipants()) {
            throw new ParticipantLimitReachedException();
        }

        if (! $isOrganizer && $meeting->waiting_room_enabled) {
            return $this->meetings->addWaitingRoomEntry($meeting, $user->id, null);
        }

        $this->activateMeetingIfNeeded($meeting);
        $role = $isOrganizer ? MeetingParticipant::HOST : MeetingParticipant::PARTICIPANT;
        $participant = $this->meetings->addParticipant($meeting, $user->id, null, $role);
        $this->addConversationMember($meeting, $user->id);
        $this->meetings->log($meeting, $user->id, 'participant_joined');

        return $participant;
    }

    /**
     * FR-316 Guest Meeting Access - no registration, just a display name.
     */
    public function guestJoin(Meeting $meeting, string $guestName, ?string $password = null): MeetingGuestSession
    {
        $this->assertJoinable($meeting, $password);

        if (! $meeting->guest_enabled) {
            throw new GuestAccessDisabledException();
        }

        if ($this->meetings->activeParticipantCount($meeting->id) >= self::maxParticipants()) {
            throw new ParticipantLimitReachedException();
        }

        if ($meeting->waiting_room_enabled) {
            // A pending guest session is created up front (unlike a
            // registered user's waiting-room entry) so the guest has
            // a session_token to poll their own admission status with
            // right away - they have no other identity to poll under.
            $session = MeetingGuestSession::create([
                'meeting_id' => $meeting->id,
                'guest_name' => $guestName,
                'session_token' => Str::random(64),
                'expires_at' => now()->addMinutes(self::guestSessionMinutes()),
                'status' => 'pending',
            ]);

            $this->meetings->addWaitingRoomEntry($meeting, null, $guestName);

            return $session;
        }

        return $this->activateGuestSession($meeting, $guestName);
    }

    private function activateGuestSession(Meeting $meeting, string $guestName, string $role = MeetingParticipant::PARTICIPANT): MeetingGuestSession
    {
        $this->activateMeetingIfNeeded($meeting);

        $session = MeetingGuestSession::create([
            'meeting_id' => $meeting->id,
            'guest_name' => $guestName,
            'session_token' => Str::random(64),
            'joined_at' => now(),
            'expires_at' => now()->addMinutes(self::guestSessionMinutes()),
            'status' => 'active',
        ]);

        $this->meetings->addParticipant($meeting, null, $session->id, $role);
        $this->meetings->log($meeting, null, $role === MeetingParticipant::HOST ? 'guest_meeting_created' : 'guest_joined', ['guest_name' => $guestName]);

        return $session;
    }

    private function activatePendingGuestSession(MeetingGuestSession $session): MeetingGuestSession
    {
        $this->activateMeetingIfNeeded($session->meeting);

        $session->forceFill([
            'joined_at' => now(),
            'expires_at' => now()->addMinutes(self::guestSessionMinutes()),
            'status' => 'active',
        ])->save();

        $this->meetings->addParticipant($session->meeting, null, $session->id, MeetingParticipant::PARTICIPANT);
        $this->meetings->log($session->meeting, null, 'guest_joined', ['guest_name' => $session->guest_name]);

        return $session;
    }

    private function assertJoinable(Meeting $meeting, ?string $password): void
    {
        if (in_array($meeting->status, ['ended', 'cancelled'], true)) {
            throw new MeetingNotActiveException('This meeting has ended.');
        }

        if ($meeting->status === 'locked') {
            throw new MeetingLockedException();
        }

        if ($meeting->hasPassword() && (! $password || ! Hash::check($password, $meeting->password))) {
            throw new InvalidMeetingPasswordException();
        }
    }

    private function activateMeetingIfNeeded(Meeting $meeting): void
    {
        if ($meeting->status === 'scheduled') {
            $meeting->forceFill(['status' => 'active', 'actual_start' => now()])->save();
        }
    }

    public function waitingRoom(Meeting $meeting, User $actor): Collection
    {
        $this->assertHostOrCoHost($meeting, $actor);

        return $this->meetings->waitingRoomFor($meeting->id);
    }

    /**
     * Lets the joining user (not the host) poll whether they've been
     * admitted yet - not itself a documented API, but required so the
     * pre-join lobby can detect the host's admit/reject decision
     * without a push channel (same reasoning as Calls' incoming poll).
     */
    public function myWaitingStatus(Meeting $meeting, User $user): ?MeetingWaitingRoom
    {
        return $this->meetings->myWaitingEntry($meeting->id, $user->id);
    }

    public function admit(Meeting $meeting, User $actor, MeetingWaitingRoom $entry): MeetingParticipant|MeetingGuestSession
    {
        $this->assertHostOrCoHost($meeting, $actor);

        $entry->forceFill(['status' => 'admitted', 'admitted_at' => now()])->save();

        if ($entry->participant_type === 'guest') {
            $pendingSession = MeetingGuestSession::where('meeting_id', $meeting->id)
                ->where('guest_name', $entry->guest_name)
                ->where('status', 'pending')
                ->latest('id')
                ->firstOrFail();

            $session = $this->activatePendingGuestSession($pendingSession);
            $this->meetings->log($meeting, $actor->id, 'guest_admitted', ['guest_name' => $entry->guest_name]);

            return $session;
        }

        $this->activateMeetingIfNeeded($meeting);
        $participant = $this->meetings->addParticipant($meeting, $entry->user_id, null, MeetingParticipant::PARTICIPANT);
        $this->addConversationMember($meeting, $entry->user_id);
        $this->meetings->log($meeting, $actor->id, 'participant_admitted', ['user_id' => $entry->user_id]);

        return $participant;
    }

    public function reject(Meeting $meeting, User $actor, MeetingWaitingRoom $entry): void
    {
        $this->assertHostOrCoHost($meeting, $actor);

        $entry->forceFill(['status' => 'rejected'])->save();
        $this->meetings->log($meeting, $actor->id, 'participant_rejected', ['participant_type' => $entry->participant_type]);
    }

    public function leave(Meeting $meeting, MeetingParticipant $participant): void
    {
        $participant->forceFill(['left_at' => now()])->save();
        $this->meetings->log($meeting, $participant->user_id, 'participant_left');
    }

    /**
     * FR-133 End Meeting for Everyone (host/co-host only) - ends the
     * session for all active participants and computes FR-135's report.
     */
    public function end(Meeting $meeting, User $actor): Meeting
    {
        $this->assertHostOrCoHost($meeting, $actor);

        $endedAt = now();
        $activeParticipants = $meeting->activeParticipants()->get();
        $activeParticipants->each(fn (MeetingParticipant $p) => $p->forceFill(['left_at' => $endedAt])->save());

        $meeting->forceFill([
            'status' => 'ended',
            'actual_end' => $endedAt,
        ])->save();

        $this->recordReport($meeting, $endedAt);
        $this->meetings->log($meeting, $actor->id, 'meeting_ended');

        return $meeting;
    }

    private function recordReport(Meeting $meeting, \Illuminate\Support\Carbon $endedAt): void
    {
        $duration = $meeting->actual_start ? $meeting->actual_start->diffInSeconds($endedAt) : 0;
        $allParticipants = $meeting->participants()->get();

        MeetingReport::updateOrCreate(['meeting_id' => $meeting->id], [
            'duration_seconds' => $duration,
            'total_participants' => $allParticipants->count(),
            'guest_count' => $allParticipants->whereNotNull('guest_session_id')->count(),
        ]);
    }

    public function mute(Meeting $meeting, User $actor, MeetingParticipant $target): void
    {
        $this->assertHostOrCoHost($meeting, $actor);
        $target->forceFill(['microphone_enabled' => false])->save();
        $this->meetings->log($meeting, $actor->id, 'participant_muted', ['participant_id' => $target->id]);
    }

    public function remove(Meeting $meeting, User $actor, MeetingParticipant $target): void
    {
        $this->assertHostOrCoHost($meeting, $actor);

        if ($target->role === MeetingParticipant::HOST) {
            throw new HostCannotBeRemovedException();
        }

        $target->forceFill(['left_at' => now()])->save();
        $this->meetings->log($meeting, $actor->id, 'participant_removed', ['participant_id' => $target->id]);
    }

    public function makeCoHost(Meeting $meeting, User $actor, MeetingParticipant $target): void
    {
        $this->assertHost($meeting, $actor);
        $target->forceFill(['role' => MeetingParticipant::CO_HOST])->save();
        $this->meetings->log($meeting, $actor->id, 'co_host_granted', ['participant_id' => $target->id]);
    }

    public function revokeCoHost(Meeting $meeting, User $actor, MeetingParticipant $target): void
    {
        $this->assertHost($meeting, $actor);
        $target->forceFill(['role' => MeetingParticipant::PARTICIPANT])->save();
        $this->meetings->log($meeting, $actor->id, 'co_host_revoked', ['participant_id' => $target->id]);
    }

    public function transferHost(Meeting $meeting, User $actor, MeetingParticipant $newHost): void
    {
        $current = $this->assertHost($meeting, $actor);
        $current->forceFill(['role' => MeetingParticipant::CO_HOST])->save();
        $newHost->forceFill(['role' => MeetingParticipant::HOST])->save();
        $this->meetings->log($meeting, $actor->id, 'host_transferred', ['new_host_participant_id' => $newHost->id]);
    }

    public function setLocked(Meeting $meeting, User $actor, bool $locked): Meeting
    {
        $participant = $this->assertHostOrCoHost($meeting, $actor);

        return $this->applyLock($meeting, $participant, $locked);
    }

    /**
     * A guest-created meeting's creator is its host too (see
     * guestCreateInstant()) but has no User to resolve via setLocked()
     * above - the guest room's own lock button was previously entirely
     * missing because of this gap (this was the actual "lock/unlock
     * doesn't work" report - the registered room's lock button itself
     * was already correct).
     */
    public function setLockedByParticipant(Meeting $meeting, MeetingParticipant $participant, bool $locked): Meeting
    {
        if (! $participant->isHostOrCoHost()) {
            throw new AuthorizationException('Only the host or co-host may do this.');
        }

        return $this->applyLock($meeting, $participant, $locked);
    }

    private function applyLock(Meeting $meeting, MeetingParticipant $actor, bool $locked): Meeting
    {
        $meeting->forceFill(['status' => $locked ? 'locked' : 'active'])->save();
        $this->meetings->log($meeting, $actor->user_id, $locked ? 'meeting_locked' : 'meeting_unlocked');

        return $meeting;
    }

    public function toggleHand(MeetingParticipant $participant, bool $raised): void
    {
        $participant->forceFill(['hand_raised' => $raised])->save();
    }

    public function setMediaState(MeetingParticipant $participant, ?bool $microphone, ?bool $camera): void
    {
        $participant->forceFill(array_filter([
            'microphone_enabled' => $microphone,
            'camera_enabled' => $camera,
        ], fn ($v) => $v !== null))->save();
    }

    /**
     * FR-130 Screen Sharing. Permission mode gates who may start;
     * only one active sharer at a time (undocumented for meetings,
     * but consistent with the explicit 1:1-call rule and simpler UX).
     */
    public function startScreenShare(Meeting $meeting, MeetingParticipant $participant): void
    {
        $settings = $meeting->settings;

        if ($settings->screen_share_permission === 'host_only' && ! $participant->isHostOrCoHost()) {
            throw new AuthorizationException('Only the host may share their screen in this meeting.');
        }

        if ($meeting->activeParticipants()->where('screen_sharing', true)->exists()) {
            throw new ScreenShareInUseException();
        }

        $participant->forceFill(['screen_sharing' => true])->save();
        $this->meetings->log($meeting, $participant->user_id, 'screen_share_started');
    }

    public function stopScreenShare(Meeting $meeting, MeetingParticipant $participant): void
    {
        $participant->forceFill(['screen_sharing' => false])->save();
        $this->meetings->log($meeting, $participant->user_id, 'screen_share_stopped');
    }

    /**
     * Live roster for the call UI specifically - unlike
     * Meeting::activeParticipants() (whereNull('left_at') only, still
     * used as-is for capacity counting and for end()'s "mark everyone
     * left" sweep), this also excludes anyone not heartbeated recently
     * (see touchLastSeen(), called from every roster poll) so a
     * participant who vanished without clicking Leave stops being
     * treated as present instead of showing up as a phantom tile
     * forever.
     */
    public function roster(Meeting $meeting): Collection
    {
        return $meeting->activeParticipants()
            ->where(function ($query) {
                $query->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '>=', now()->subSeconds(self::staleParticipantSeconds()));
            })
            ->with(['user', 'guestSession'])
            ->orderBy('joined_at')
            ->get();
    }

    /**
     * Called from every roster poll (MeetingController::roster(),
     * GuestMeetingController::roster()) by the participant making the
     * request, about themselves - this is the heartbeat roster() above
     * filters on.
     */
    public function touchLastSeen(MeetingParticipant $participant): void
    {
        $participant->forceFill(['last_seen_at' => now()])->save();
    }

    public function sendOffer(MeetingParticipant $sender, int $receiverParticipantId, string $sdp): void
    {
        $this->meetings->createSignal($sender->meeting_id, $sender->id, $receiverParticipantId, 'offer', $sdp);
    }

    public function sendAnswer(MeetingParticipant $sender, int $receiverParticipantId, string $sdp): void
    {
        $this->meetings->createSignal($sender->meeting_id, $sender->id, $receiverParticipantId, 'answer', $sdp);
    }

    public function sendIceCandidate(MeetingParticipant $sender, int $receiverParticipantId, string $candidate): void
    {
        $this->meetings->createIceCandidate($sender->meeting_id, $sender->id, $receiverParticipantId, $candidate);
    }

    public function pollSignals(MeetingParticipant $participant, int $afterSignalId, int $afterIceId): array
    {
        return [
            'signals' => $this->meetings->signalsFor($participant->id, $afterSignalId),
            'ice_candidates' => $this->meetings->iceCandidatesFor($participant->id, $afterIceId),
        ];
    }

    public function guestSessionStatus(MeetingGuestSession $session): array
    {
        if ($session->status === 'active' && $session->isExpired()) {
            $session->forceFill(['status' => 'expired'])->save();

            $participant = MeetingParticipant::where('guest_session_id', $session->id)->whereNull('left_at')->first();
            $participant?->forceFill(['left_at' => now()])->save();
        }

        if ($session->status === 'expired') {
            throw new GuestSessionExpiredException();
        }

        $waitingEntry = $session->status === 'pending'
            ? MeetingWaitingRoom::where('meeting_id', $session->meeting_id)
                ->where('participant_type', 'guest')
                ->where('guest_name', $session->guest_name)
                ->latest('id')
                ->first()
            : null;

        return [
            'status' => $waitingEntry && $waitingEntry->status === 'rejected' ? 'rejected' : $session->status,
            'session' => $session,
        ];
    }

    public function history(User $user): Collection
    {
        return $this->meetings->history($user->id);
    }

    public function upcoming(User $user): Collection
    {
        return $this->meetings->upcoming($user->id);
    }

    public function assertParticipant(Meeting $meeting, MeetingParticipant $participant): void
    {
        if ($participant->meeting_id !== $meeting->id) {
            throw new AuthorizationException('This participant does not belong to this meeting.');
        }
    }

    public function participantFor(Meeting $meeting, User $user): MeetingParticipant
    {
        $participant = $this->meetings->findActiveParticipant($meeting->id, $user->id);

        if (! $participant) {
            throw new AuthorizationException('You are not an active participant in this meeting.');
        }

        return $participant;
    }

    public function participantForGuestSession(MeetingGuestSession $session): MeetingParticipant
    {
        $participant = MeetingParticipant::where('guest_session_id', $session->id)->whereNull('left_at')->first();

        if (! $participant) {
            throw new AuthorizationException('This guest session is not an active participant.');
        }

        return $participant;
    }

    /**
     * Every other guest endpoint is scoped entirely by {guestSession}
     * itself (roster, offer/answer, etc.), so the guest session is all
     * the meeting-scoping that's needed. Whiteboard routes also carry a
     * separate {whiteboard} route param though - without this extra
     * check, a guest could pass another meeting's whiteboard id and
     * touch it despite never having joined that meeting.
     */
    public function participantForGuestSessionAndMeeting(MeetingGuestSession $session, Meeting $meeting): MeetingParticipant
    {
        if ($session->meeting_id !== $meeting->id) {
            throw new AuthorizationException('This guest session does not belong to that meeting.');
        }

        return $this->participantForGuestSession($session);
    }

    private function assertHostOrCoHost(Meeting $meeting, User $actor): MeetingParticipant
    {
        $participant = $this->meetings->findActiveParticipant($meeting->id, $actor->id);

        if (! $participant || ! $participant->isHostOrCoHost()) {
            throw new AuthorizationException('Only the host or co-host may do this.');
        }

        return $participant;
    }

    private function assertHost(Meeting $meeting, User $actor): MeetingParticipant
    {
        $participant = $this->meetings->findActiveParticipant($meeting->id, $actor->id);

        if (! $participant || $participant->role !== MeetingParticipant::HOST) {
            throw new AuthorizationException('Only the host may do this.');
        }

        return $participant;
    }
}
