<?php

namespace Modules\Meetings\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Meetings\Http\Requests\CreateInstantMeetingRequest;
use Modules\Meetings\Http\Requests\GuestJoinRequest;
use Modules\Meetings\Http\Requests\JoinMeetingRequest;
use Modules\Meetings\Http\Requests\ScheduleMeetingRequest;
use Modules\Meetings\Http\Resources\GuestSessionResource;
use Modules\Meetings\Http\Resources\MeetingParticipantResource;
use Modules\Meetings\Http\Resources\MeetingResource;
use Modules\Meetings\Http\Resources\MeetingWaitingRoomResource;
use Modules\Meetings\Models\Meeting;
use Modules\Meetings\Models\MeetingGuestSession;
use Modules\Meetings\Models\MeetingParticipant;
use Modules\Meetings\Models\MeetingWaitingRoom;
use Modules\Meetings\Services\CalendarService;
use Modules\Meetings\Services\MeetingService;

class MeetingController extends Controller
{
    public function __construct(
        private readonly MeetingService $meetings,
        private readonly CalendarService $calendar,
    ) {
    }

    /**
     * RAS-4 API - POST /meetings/instant (FR-121)
     */
    public function instant(CreateInstantMeetingRequest $request): JsonResponse
    {
        $meeting = $this->meetings->createInstant($request->user(), $request->input('title') ?: 'Instant Meeting');

        return response()->json([
            'success' => true,
            'message' => 'Meeting started.',
            'data' => new MeetingResource($meeting->load(['organizer', 'settings'])),
            'errors' => null,
        ], 201);
    }

    /**
     * RAS-4 API - POST /meetings (FR-122). Business rule: only
     * verified users may schedule meetings.
     */
    public function schedule(ScheduleMeetingRequest $request): JsonResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'message' => 'Verify your email before scheduling meetings.',
                'data' => null,
                'errors' => null,
            ], 403);
        }

        [$participantIds, $adHocEmails] = $this->calendar->resolveInviteeEmails(
            $request->input('participant_emails', []),
            $request->input('participant_ids', []),
        );

        $meeting = $this->meetings->createScheduled($request->user(), $request->validated());
        $this->calendar->scheduleMeeting($meeting, $request->user(), $participantIds, $adHocEmails);

        return response()->json([
            'success' => true,
            'message' => 'Meeting scheduled.',
            'data' => new MeetingResource($meeting->load(['organizer', 'settings'])),
            'errors' => null,
        ], 201);
    }

    public function show(Request $request, Meeting $meeting): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => new MeetingResource($meeting->load(['organizer', 'settings'])),
            'errors' => null,
        ]);
    }

    public function cancel(Request $request, Meeting $meeting): JsonResponse
    {
        $meeting = $this->meetings->cancel($meeting, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Meeting cancelled.',
            'data' => new MeetingResource($meeting),
            'errors' => null,
        ]);
    }

    /**
     * RAS-4 API - POST /meetings/{meetingId}/join (FR-125)
     */
    public function join(JoinMeetingRequest $request, Meeting $meeting): JsonResponse
    {
        $result = $this->meetings->join($meeting, $request->user(), $request->input('password'));

        if ($result instanceof MeetingWaitingRoom) {
            return response()->json([
                'success' => true,
                'message' => 'Waiting for the host to admit you.',
                'data' => ['status' => 'waiting', 'waiting_room_id' => $result->id],
                'errors' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Joined the meeting.',
            'data' => [
                'status' => 'joined',
                'meeting' => new MeetingResource($meeting->load(['organizer', 'settings'])),
                'participant' => new MeetingParticipantResource($result),
            ],
            'errors' => null,
        ]);
    }

    /**
     * RAS-4 API - POST /meetings/{meetingId}/guest (FR-316)
     */
    public function guestJoin(GuestJoinRequest $request, Meeting $meeting): JsonResponse
    {
        $session = $this->meetings->guestJoin($meeting, $request->input('guest_name'), $request->input('password'));

        if ($session->status === 'pending') {
            return response()->json([
                'success' => true,
                'message' => 'Waiting for the host to admit you.',
                'data' => ['status' => 'waiting', 'guest_session' => new GuestSessionResource($session)],
                'errors' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Joined the meeting.',
            'data' => ['status' => 'joined', 'guest_session' => new GuestSessionResource($session)],
            'errors' => null,
        ], 201);
    }

    public function waitingRoom(Request $request, Meeting $meeting): JsonResponse
    {
        $entries = $this->meetings->waitingRoom($meeting, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => MeetingWaitingRoomResource::collection($entries),
            'errors' => null,
        ]);
    }

    public function myWaitingStatus(Request $request, Meeting $meeting): JsonResponse
    {
        $entry = $this->meetings->myWaitingStatus($meeting, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => [
                'status' => $entry?->status ?? 'unknown',
            ],
            'errors' => null,
        ]);
    }

    public function admit(Request $request, Meeting $meeting, MeetingWaitingRoom $entry): JsonResponse
    {
        $result = $this->meetings->admit($meeting, $request->user(), $entry);

        return response()->json([
            'success' => true,
            'message' => 'Admitted to the meeting.',
            'data' => $result instanceof MeetingGuestSession ? new GuestSessionResource($result) : new MeetingParticipantResource($result),
            'errors' => null,
        ]);
    }

    public function rejectWaiting(Request $request, Meeting $meeting, MeetingWaitingRoom $entry): JsonResponse
    {
        $this->meetings->reject($meeting, $request->user(), $entry);

        return response()->json(['success' => true, 'message' => 'Rejected.', 'data' => null, 'errors' => null]);
    }

    public function leave(Request $request, Meeting $meeting): JsonResponse
    {
        $participant = $this->meetings->participantFor($meeting, $request->user());
        $this->meetings->leave($meeting, $participant);

        return response()->json(['success' => true, 'message' => 'Left the meeting.', 'data' => null, 'errors' => null]);
    }

    /**
     * RAS-4 API - POST /meetings/{meetingId}/end (FR-133)
     */
    public function end(Request $request, Meeting $meeting): JsonResponse
    {
        $meeting = $this->meetings->end($meeting, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Meeting ended for everyone.',
            'data' => new MeetingResource($meeting),
            'errors' => null,
        ]);
    }

    public function roster(Request $request, Meeting $meeting): JsonResponse
    {
        $me = $this->meetings->participantFor($meeting, $request->user());
        $this->meetings->touchLastSeen($me);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => MeetingParticipantResource::collection($this->meetings->roster($meeting)),
            'errors' => null,
        ]);
    }

    public function mute(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $this->meetings->mute($meeting, $request->user(), $participant);

        return response()->json(['success' => true, 'message' => 'Participant muted.', 'data' => null, 'errors' => null]);
    }

    public function remove(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $this->meetings->remove($meeting, $request->user(), $participant);

        return response()->json(['success' => true, 'message' => 'Participant removed.', 'data' => null, 'errors' => null]);
    }

    public function makeCoHost(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $this->meetings->makeCoHost($meeting, $request->user(), $participant);

        return response()->json(['success' => true, 'message' => 'Co-host granted.', 'data' => null, 'errors' => null]);
    }

    public function revokeCoHost(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $this->meetings->revokeCoHost($meeting, $request->user(), $participant);

        return response()->json(['success' => true, 'message' => 'Co-host revoked.', 'data' => null, 'errors' => null]);
    }

    public function transferHost(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $this->meetings->transferHost($meeting, $request->user(), $participant);

        return response()->json(['success' => true, 'message' => 'Host transferred.', 'data' => null, 'errors' => null]);
    }

    public function lock(Request $request, Meeting $meeting): JsonResponse
    {
        $request->validate(['locked' => ['required', 'boolean']]);
        $meeting = $this->meetings->setLocked($meeting, $request->user(), (bool) $request->input('locked'));

        return response()->json(['success' => true, 'message' => 'Operation completed successfully.', 'data' => new MeetingResource($meeting), 'errors' => null]);
    }

    public function raiseHand(Request $request, Meeting $meeting): JsonResponse
    {
        $request->validate(['raised' => ['required', 'boolean']]);
        $participant = $this->meetings->participantFor($meeting, $request->user());
        $this->meetings->toggleHand($participant, (bool) $request->input('raised'));

        return response()->json(['success' => true, 'message' => 'Operation completed successfully.', 'data' => null, 'errors' => null]);
    }

    public function media(Request $request, Meeting $meeting): JsonResponse
    {
        $request->validate(['microphone_enabled' => ['sometimes', 'boolean'], 'camera_enabled' => ['sometimes', 'boolean']]);
        $participant = $this->meetings->participantFor($meeting, $request->user());
        $this->meetings->setMediaState($participant, $request->input('microphone_enabled'), $request->input('camera_enabled'));

        return response()->json(['success' => true, 'message' => 'Operation completed successfully.', 'data' => null, 'errors' => null]);
    }

    public function startScreenShare(Request $request, Meeting $meeting): JsonResponse
    {
        $participant = $this->meetings->participantFor($meeting, $request->user());
        $this->meetings->startScreenShare($meeting, $participant);

        return response()->json(['success' => true, 'message' => 'Screen share started.', 'data' => null, 'errors' => null], 201);
    }

    public function stopScreenShare(Request $request, Meeting $meeting): JsonResponse
    {
        $participant = $this->meetings->participantFor($meeting, $request->user());
        $this->meetings->stopScreenShare($meeting, $participant);

        return response()->json(['success' => true, 'message' => 'Screen share stopped.', 'data' => null, 'errors' => null]);
    }

    public function history(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => MeetingResource::collection($this->meetings->history($request->user())),
            'errors' => null,
        ]);
    }

    public function upcoming(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => MeetingResource::collection($this->meetings->upcoming($request->user())),
            'errors' => null,
        ]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $meeting = Meeting::where('meeting_code', $request->input('code'))->first();

        return response()->json([
            'success' => (bool) $meeting,
            'message' => $meeting ? 'Meeting found.' : 'No meeting found with that code.',
            'data' => $meeting ? new MeetingResource($meeting) : null,
            'errors' => null,
        ], $meeting ? 200 : 404);
    }
}
