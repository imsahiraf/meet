<?php

namespace Modules\Calls\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Calls\Models\Call;
use Modules\Calls\Services\CallService;

/**
 * Multi-party mesh signaling for calls. With 3+ people in a call an
 * offer/answer/ICE candidate is never "for the call" - it belongs to one
 * specific PAIR within it, so every one of those carries an explicit
 * receiver_id and the poll tells you which peer each incoming signal
 * came from. This mirrors MeetingSignalingController's proven per-pair
 * scoping; the one difference is the key, a plain user id here rather
 * than a meeting_participants.id, because a call has no guests and
 * therefore every party always has a real user id.
 *
 * Reactions and screen-share start/stop are deliberately NOT
 * pair-scoped - they're room-wide events, fanned out server-side to
 * everyone else in the call (see CallService::fanOutSignal()).
 */
class SignalingController extends Controller
{
    public function __construct(
        private readonly CallService $calls,
    ) {
    }

    /**
     * RAS-4 API7 - POST /calls/{callId}/offer
     */
    public function offer(Request $request, Call $call): JsonResponse
    {
        $request->validate(['receiver_id' => ['required', 'integer'], 'sdp' => ['required', 'string']]);
        $this->calls->sendOffer($call, $request->user(), (int) $request->input('receiver_id'), $request->input('sdp'));

        return response()->json(['success' => true, 'message' => 'Offer sent.', 'data' => null, 'errors' => null], 201);
    }

    /**
     * RAS-4 API8 - POST /calls/{callId}/answer
     */
    public function answer(Request $request, Call $call): JsonResponse
    {
        $request->validate(['receiver_id' => ['required', 'integer'], 'sdp' => ['required', 'string']]);
        $this->calls->sendAnswer($call, $request->user(), (int) $request->input('receiver_id'), $request->input('sdp'));

        return response()->json(['success' => true, 'message' => 'Answer sent.', 'data' => null, 'errors' => null], 201);
    }

    /**
     * RAS-4 API9 - POST /calls/{callId}/ice
     */
    public function ice(Request $request, Call $call): JsonResponse
    {
        $request->validate(['receiver_id' => ['required', 'integer'], 'candidate' => ['required', 'string']]);
        $this->calls->sendIceCandidate($call, $request->user(), (int) $request->input('receiver_id'), $request->input('candidate'));

        return response()->json(['success' => true, 'message' => 'Candidate sent.', 'data' => null, 'errors' => null], 201);
    }

    /**
     * A quick emoji reaction during a call - not in RAS-4, rides the
     * same signal channel offer/answer/ice already use, fanned out to
     * everyone else in the call rather than one specific peer.
     */
    public function reaction(Request $request, Call $call): JsonResponse
    {
        $request->validate(['emoji' => ['required', 'string', 'max:8']]);
        $this->calls->sendReaction($call, $request->user(), $request->input('emoji'));

        return response()->json(['success' => true, 'message' => 'Reaction sent.', 'data' => null, 'errors' => null], 201);
    }

    /**
     * Screen-share start/stop signal - not in RAS-4, same fan-out as a
     * reaction (everyone needs to know who started sharing, not just one
     * peer).
     */
    public function screenShare(Request $request, Call $call): JsonResponse
    {
        $request->validate(['state' => ['required', 'string', 'in:started,stopped']]);
        $this->calls->sendScreenShareSignal($call, $request->user(), $request->input('state'));

        return response()->json(['success' => true, 'message' => 'Screen share signal sent.', 'data' => null, 'errors' => null], 201);
    }

    /**
     * In-call events (captions, polls, votes, questions, video on, low data) - fanned out like a reaction.
     */
    public function event(Request $request, Call $call): JsonResponse
    {
        $request->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', \Modules\Calls\Services\CallService::EVENT_KINDS)],
            'data' => ['present', 'array'],
        ]);
        try {
            $this->calls->sendEvent($call, $request->user(), $request->input('kind'), (array) $request->input('data', []));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'data' => null, 'errors' => null], 422);
        }

        return response()->json(['success' => true, 'message' => 'Event sent.', 'data' => null, 'errors' => null], 201);
    }

    /**
     * Not in RAS-4 (which only documents the send/POST side of
     * signaling, not how the peer retrieves it) - see the Phase 4
     * research notes. Client tracks two independent "after" cursors,
     * the same proven pattern as Chat's message polling. sender_id is
     * what makes this N-party usable: it's how the client knows which
     * of its several peer connections an offer/answer/candidate belongs
     * to (the meeting room's poll returns sender_participant_id for the
     * same reason).
     */
    public function poll(Request $request, Call $call): JsonResponse
    {
        $result = $this->calls->pollSignals(
            $call,
            $request->user(),
            (int) $request->input('after_signal_id', 0),
            (int) $request->input('after_ice_id', 0),
        );

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => [
                'signals' => $result['signals']->map(fn ($s) => [
                    'id' => $s->id,
                    'sender_id' => $s->sender_id,
                    'signal_type' => $s->signal_type,
                    'payload' => $s->payload,
                ]),
                'ice_candidates' => $result['ice_candidates']->map(fn ($c) => [
                    'id' => $c->id,
                    'sender_id' => $c->sender_id,
                    'candidate' => $c->candidate,
                ]),
            ],
            'errors' => null,
        ]);
    }
}
