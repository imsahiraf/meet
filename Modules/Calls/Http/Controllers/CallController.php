<?php

namespace Modules\Calls\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authentication\Models\User;
use Modules\Calls\Http\Requests\StartCallRequest;
use Modules\Calls\Http\Resources\CallResource;
use Modules\Calls\Models\Call;
use Modules\Calls\Services\CallService;

class CallController extends Controller
{
    /**
     * Everything a CallResource reads: the originating pair for display
     * plus the real participant list (with each person's user record for
     * their name/avatar in the grid).
     */
    private const WITH = ['caller', 'receiver', 'participants.user'];

    public function __construct(
        private readonly CallService $calls,
    ) {
    }

    /**
     * RAS-4 API1 - POST /calls/voice/start
     */
    public function startVoice(StartCallRequest $request): JsonResponse
    {
        return $this->start($request, 'voice');
    }

    /**
     * RAS-4 API2 - POST /calls/video/start
     */
    public function startVideo(StartCallRequest $request): JsonResponse
    {
        return $this->start($request, 'video');
    }

    private function start(StartCallRequest $request, string $type): JsonResponse
    {
        $call = $this->calls->start(
            $request->user(),
            (int) $request->input('receiver_id'),
            $type,
            $request->input('conversation_id'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Call started.',
            'data' => new CallResource($call->load(self::WITH)),
            'errors' => null,
        ], 201);
    }

    /**
     * RAS-4 API3 - POST /calls/{callId}/accept
     */
    public function accept(Request $request, Call $call): JsonResponse
    {
        $call = $this->calls->accept($call, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Call accepted.',
            'data' => new CallResource($call->load(self::WITH)),
            'errors' => null,
        ]);
    }

    /**
     * RAS-4 API4 - POST /calls/{callId}/reject
     */
    public function reject(Request $request, Call $call): JsonResponse
    {
        $call = $this->calls->reject($call, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Call rejected.',
            'data' => new CallResource($call->load(self::WITH)),
            'errors' => null,
        ]);
    }

    /**
     * RAS-4 API5 - POST /calls/{callId}/end. With 3+ people this is
     * "leave the call"; the call itself only ends once fewer than two
     * people are left in it (see CallService::end()).
     */
    public function end(Request $request, Call $call): JsonResponse
    {
        $call = $this->calls->end($call, $request->user());

        return response()->json([
            'success' => true,
            'message' => $call->isActive() ? 'You left the call.' : 'Call ended.',
            'data' => new CallResource($call->load(self::WITH)),
            'errors' => null,
        ]);
    }

    /**
     * Real mute/camera-off signal to everyone else in the call - see
     * Call::mediaStateFor()/CallService::updateMedia().
     */
    public function updateMedia(Request $request, Call $call): JsonResponse
    {
        $request->validate(['audio_enabled' => ['sometimes', 'boolean'], 'video_enabled' => ['sometimes', 'boolean']]);

        $call = $this->calls->updateMedia(
            $call,
            $request->user(),
            $request->has('audio_enabled') ? $request->boolean('audio_enabled') : null,
            $request->has('video_enabled') ? $request->boolean('video_enabled') : null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Media state updated.',
            'data' => new CallResource($call->load(self::WITH)),
            'errors' => null,
        ]);
    }

    /**
     * "Add member" during a live call - adds a participant to THIS call
     * (see CallService::addParticipant()). It used to end the call and
     * escalate everyone into a freshly created Meeting instead; calls
     * are natively N-party now, so nothing ends and nobody navigates
     * anywhere - the new person just shows up as another tile in
     * everyone's grid via the polls already running.
     */
    public function addMember(Request $request, Call $call): JsonResponse
    {
        $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);

        $call = $this->calls->addParticipant(
            $call,
            $request->user(),
            User::findOrFail((int) $request->input('user_id')),
        );

        return response()->json([
            'success' => true,
            'message' => 'Member added to the call.',
            'data' => new CallResource($call->load(self::WITH)),
            'errors' => null,
        ], 201);
    }

    /**
     * Status poll - not separately in RAS-4 (which only lists
     * mutating actions), but required for every participant to detect
     * state transitions the others made (accept/reject/end, plus who
     * has joined or left) without a push channel. This is also what
     * drives the client's participant grid: the response's
     * `participants` array is the roster.
     */
    public function show(Request $request, Call $call): JsonResponse
    {
        $this->calls->authorizeParticipant($call, $request->user());

        // Only the caller's own client polls this while the call is
        // still ringing (see resendRingingPushIfDue()'s docblock) -
        // gating on that here too rather than trusting call_status
        // alone, so some other participant's own unrelated poll of
        // this same endpoint can never double up the repeat push.
        if ($request->user()->id === $call->caller_id) {
            $this->calls->resendRingingPushIfDue($call);
        }

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => new CallResource($call->load(self::WITH)),
            'errors' => null,
        ]);
    }

    /**
     * Global "is there a call I should be seeing right now" check - not
     * in RAS-4 either, but required to show the incoming-call UI from any
     * page without a push channel (mirrors the presence heartbeat's
     * always-on polling from the shared layout). Covers both a ringing
     * call aimed at me AND a call already in progress that somebody just
     * added me to - the client tells them apart by call_status.
     */
    public function incoming(Request $request): JsonResponse
    {
        $call = $this->calls->incomingCallFor($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => $call ? new CallResource($call->load(self::WITH)) : null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-4 API6 - GET /calls/history
     */
    public function history(Request $request): JsonResponse
    {
        $calls = $this->calls->history(
            $request->user(),
            $request->input('status'),
            $request->input('with_user_id') ? (int) $request->input('with_user_id') : null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => CallResource::collection($calls),
            'errors' => null,
        ]);
    }
}
