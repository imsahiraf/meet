<?php

namespace Modules\Meetings\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Meetings\Models\Meeting;
use Modules\Meetings\Services\MeetingService;

/**
 * Multi-party mesh signaling - see the rationale comment on the
 * meeting_signals migration. Every participant pair negotiates its
 * own offer/answer/ICE exchange, keyed by meeting_participants.id
 * rather than user id (so a guest, who has no user id, can be a
 * signaling party too).
 */
class MeetingSignalingController extends Controller
{
    public function __construct(
        private readonly MeetingService $meetings,
    ) {
    }

    public function offer(Request $request, Meeting $meeting): JsonResponse
    {
        $request->validate(['receiver_participant_id' => ['required', 'integer'], 'sdp' => ['required', 'string']]);
        $sender = $this->meetings->participantFor($meeting, $request->user());
        $this->meetings->sendOffer($sender, (int) $request->input('receiver_participant_id'), $request->input('sdp'));

        return response()->json(['success' => true, 'message' => 'Offer sent.', 'data' => null, 'errors' => null], 201);
    }

    public function answer(Request $request, Meeting $meeting): JsonResponse
    {
        $request->validate(['receiver_participant_id' => ['required', 'integer'], 'sdp' => ['required', 'string']]);
        $sender = $this->meetings->participantFor($meeting, $request->user());
        $this->meetings->sendAnswer($sender, (int) $request->input('receiver_participant_id'), $request->input('sdp'));

        return response()->json(['success' => true, 'message' => 'Answer sent.', 'data' => null, 'errors' => null], 201);
    }

    public function ice(Request $request, Meeting $meeting): JsonResponse
    {
        $request->validate(['receiver_participant_id' => ['required', 'integer'], 'candidate' => ['required', 'string']]);
        $sender = $this->meetings->participantFor($meeting, $request->user());
        $this->meetings->sendIceCandidate($sender, (int) $request->input('receiver_participant_id'), $request->input('candidate'));

        return response()->json(['success' => true, 'message' => 'Candidate sent.', 'data' => null, 'errors' => null], 201);
    }

    public function poll(Request $request, Meeting $meeting): JsonResponse
    {
        $participant = $this->meetings->participantFor($meeting, $request->user());
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
