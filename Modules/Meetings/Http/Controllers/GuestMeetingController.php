<?php

namespace Modules\Meetings\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Meetings\Http\Resources\GuestSessionResource;
use Modules\Meetings\Http\Resources\MeetingParticipantResource;
use Modules\Meetings\Http\Resources\MeetingResource;
use Modules\Meetings\Models\MeetingGuestSession;
use Modules\Meetings\Services\MeetingService;

/**
 * FR-316/FR-321 - guests never authenticate via Sanctum; every action
 * here is scoped by the unguessable session_token embedded in their
 * join link instead (see routes/api.php - these routes sit outside
 * the auth:sanctum group).
 */
class GuestMeetingController extends Controller
{
    public function __construct(
        private readonly MeetingService $meetings,
    ) {
    }

    public function status(Request $request, MeetingGuestSession $guestSession): JsonResponse
    {
        $result = $this->meetings->guestSessionStatus($guestSession);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => [
                'status' => $result['status'],
                'guest_session' => new GuestSessionResource($result['session']),
                'meeting' => $result['status'] === 'active' ? new MeetingResource($guestSession->meeting->load(['organizer', 'settings'])) : null,
            ],
            'errors' => null,
        ]);
    }

    public function roster(Request $request, MeetingGuestSession $guestSession): JsonResponse
    {
        $me = $this->meetings->participantForGuestSession($guestSession);
        $this->meetings->touchLastSeen($me);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => MeetingParticipantResource::collection($this->meetings->roster($guestSession->meeting)),
            'meta' => ['my_participant_id' => $me->id],
            'errors' => null,
        ]);
    }

    public function media(Request $request, MeetingGuestSession $guestSession): JsonResponse
    {
        $request->validate(['microphone_enabled' => ['sometimes', 'boolean'], 'camera_enabled' => ['sometimes', 'boolean']]);
        $participant = $this->meetings->participantForGuestSession($guestSession);
        $this->meetings->setMediaState($participant, $request->input('microphone_enabled'), $request->input('camera_enabled'));

        return response()->json(['success' => true, 'message' => 'Operation completed successfully.', 'data' => null, 'errors' => null]);
    }

    public function leave(Request $request, MeetingGuestSession $guestSession): JsonResponse
    {
        $participant = $this->meetings->participantForGuestSession($guestSession);
        $this->meetings->leave($guestSession->meeting, $participant);

        return response()->json(['success' => true, 'message' => 'Left the meeting.', 'data' => null, 'errors' => null]);
    }

    /**
     * The creator of a guest-created meeting IS its host (see
     * MeetingService::guestCreateInstant()) - this was previously
     * unreachable entirely (no guest route existed for it at all, the
     * guest room had no lock button in its markup either).
     */
    public function lock(Request $request, MeetingGuestSession $guestSession): JsonResponse
    {
        $request->validate(['locked' => ['required', 'boolean']]);
        $participant = $this->meetings->participantForGuestSession($guestSession);
        $meeting = $this->meetings->setLockedByParticipant($guestSession->meeting, $participant, (bool) $request->input('locked'));

        return response()->json(['success' => true, 'message' => 'Operation completed successfully.', 'data' => new MeetingResource($meeting), 'errors' => null]);
    }

    /**
     * Existed in the registered-user room already - the guest room's
     * Share screen button was previously wired to nothing at all.
     */
    public function startScreenShare(Request $request, MeetingGuestSession $guestSession): JsonResponse
    {
        $participant = $this->meetings->participantForGuestSession($guestSession);
        $this->meetings->startScreenShare($guestSession->meeting, $participant);

        return response()->json(['success' => true, 'message' => 'Screen share started.', 'data' => null, 'errors' => null], 201);
    }

    public function stopScreenShare(Request $request, MeetingGuestSession $guestSession): JsonResponse
    {
        $participant = $this->meetings->participantForGuestSession($guestSession);
        $this->meetings->stopScreenShare($guestSession->meeting, $participant);

        return response()->json(['success' => true, 'message' => 'Screen share stopped.', 'data' => null, 'errors' => null]);
    }

    public function offer(Request $request, MeetingGuestSession $guestSession): JsonResponse
    {
        $request->validate(['receiver_participant_id' => ['required', 'integer'], 'sdp' => ['required', 'string']]);
        $sender = $this->meetings->participantForGuestSession($guestSession);
        $this->meetings->sendOffer($sender, (int) $request->input('receiver_participant_id'), $request->input('sdp'));

        return response()->json(['success' => true, 'message' => 'Offer sent.', 'data' => null, 'errors' => null], 201);
    }

    public function answer(Request $request, MeetingGuestSession $guestSession): JsonResponse
    {
        $request->validate(['receiver_participant_id' => ['required', 'integer'], 'sdp' => ['required', 'string']]);
        $sender = $this->meetings->participantForGuestSession($guestSession);
        $this->meetings->sendAnswer($sender, (int) $request->input('receiver_participant_id'), $request->input('sdp'));

        return response()->json(['success' => true, 'message' => 'Answer sent.', 'data' => null, 'errors' => null], 201);
    }

    public function ice(Request $request, MeetingGuestSession $guestSession): JsonResponse
    {
        $request->validate(['receiver_participant_id' => ['required', 'integer'], 'candidate' => ['required', 'string']]);
        $sender = $this->meetings->participantForGuestSession($guestSession);
        $this->meetings->sendIceCandidate($sender, (int) $request->input('receiver_participant_id'), $request->input('candidate'));

        return response()->json(['success' => true, 'message' => 'Candidate sent.', 'data' => null, 'errors' => null], 201);
    }

    public function poll(Request $request, MeetingGuestSession $guestSession): JsonResponse
    {
        $participant = $this->meetings->participantForGuestSession($guestSession);
        $result = $this->meetings->pollSignals(
            $participant,
            (int) $request->input('after_signal_id', 0),
            (int) $request->input('after_ice_id', 0),
        );

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => [
                'signals' => $result['signals']->map(fn ($s) => [
                    'id' => $s->id,
                    'sender_participant_id' => $s->sender_participant_id,
                    'signal_type' => $s->signal_type,
                    'payload' => $s->payload,
                ]),
                'ice_candidates' => $result['ice_candidates']->map(fn ($c) => [
                    'id' => $c->id,
                    'sender_participant_id' => $c->sender_participant_id,
                    'candidate' => $c->candidate,
                ]),
            ],
            'errors' => null,
        ]);
    }
}
