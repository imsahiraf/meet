<?php

namespace Modules\Whiteboard\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Media\Models\Media;
use Modules\Meetings\Models\MeetingGuestSession;
use Modules\Meetings\Services\MeetingService;
use Modules\Whiteboard\Http\Resources\WhiteboardObjectResource;
use Modules\Whiteboard\Http\Resources\WhiteboardResource;
use Modules\Whiteboard\Models\Whiteboard;
use Modules\Whiteboard\Models\WhiteboardObject;
use Modules\Whiteboard\Models\WhiteboardPage;
use Modules\Whiteboard\Services\WhiteboardService;

/**
 * Mirrors WhiteboardController's every action, but for guests - who
 * have no Sanctum session and therefore can't use auth:sanctum-gated
 * routes at all. See this module's guest-support migration/service
 * changes for how a guest participant is tracked without a `users`
 * row. Kept as a fully separate controller (not a shared base with
 * conditional auth) to match this app's established convention of
 * guest meeting flows being entirely parallel, not branching, code
 * paths - see GuestMeetingController/guest-room.blade.php.
 */
class GuestWhiteboardController extends Controller
{
    public function __construct(
        private readonly WhiteboardService $whiteboards,
        private readonly MeetingService $meetings,
    ) {
    }

    public function open(MeetingGuestSession $guestSession): JsonResponse
    {
        $participant = $this->meetings->participantForGuestSessionAndMeeting($guestSession, $guestSession->meeting);
        $whiteboard = $this->whiteboards->open($guestSession->meeting, $participant);

        return response()->json([
            'success' => true,
            'message' => 'Whiteboard opened.',
            'data' => new WhiteboardResource($whiteboard->load('pages')),
            'errors' => null,
        ], 201);
    }

    public function show(MeetingGuestSession $guestSession, Whiteboard $whiteboard): JsonResponse
    {
        $this->meetings->participantForGuestSessionAndMeeting($guestSession, $whiteboard->meeting);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => new WhiteboardResource($whiteboard->load('pages')),
            'errors' => null,
        ]);
    }

    public function setPermission(Request $request, MeetingGuestSession $guestSession, Whiteboard $whiteboard): JsonResponse
    {
        $request->validate(['permission_mode' => ['required', 'in:host_only,host_and_co_host,all_participants,view_only']]);
        $participant = $this->meetings->participantForGuestSessionAndMeeting($guestSession, $whiteboard->meeting);

        if (! $participant->isHostOrCoHost()) {
            return response()->json(['success' => false, 'message' => 'Only the host or co-host may change whiteboard permissions.', 'data' => null, 'errors' => null], 403);
        }

        $whiteboard = $this->whiteboards->setPermission($whiteboard, $request->input('permission_mode'));

        return response()->json(['success' => true, 'message' => 'Operation completed successfully.', 'data' => new WhiteboardResource($whiteboard), 'errors' => null]);
    }

    public function addPage(Request $request, MeetingGuestSession $guestSession, Whiteboard $whiteboard): JsonResponse
    {
        $this->meetings->participantForGuestSessionAndMeeting($guestSession, $whiteboard->meeting);
        $page = $this->whiteboards->addPage($whiteboard, $request->input('name'));

        return response()->json(['success' => true, 'message' => 'Page added.', 'data' => ['id' => $page->id, 'page_number' => $page->page_number, 'name' => $page->name], 'errors' => null], 201);
    }

    public function deletePage(MeetingGuestSession $guestSession, Whiteboard $whiteboard, WhiteboardPage $page): JsonResponse
    {
        $this->meetings->participantForGuestSessionAndMeeting($guestSession, $whiteboard->meeting);
        $this->whiteboards->deletePage($whiteboard, $page);

        return response()->json(['success' => true, 'message' => 'Page deleted.', 'data' => null, 'errors' => null]);
    }

    public function objects(Request $request, MeetingGuestSession $guestSession, Whiteboard $whiteboard, WhiteboardPage $page): JsonResponse
    {
        $this->meetings->participantForGuestSessionAndMeeting($guestSession, $whiteboard->meeting);

        $since = $request->input('since') ? Carbon::parse($request->input('since')) : null;
        $sync = $this->whiteboards->syncPage($page, (int) $request->input('after_id', 0), $since);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => [
                'created' => WhiteboardObjectResource::collection($sync['created']),
                'restored' => WhiteboardObjectResource::collection($sync['restored']),
                'removed' => $sync['removed']->values(),
                'server_time' => now()->toISOString(),
            ],
            'errors' => null,
        ]);
    }

    public function storeObject(Request $request, MeetingGuestSession $guestSession, Whiteboard $whiteboard, WhiteboardPage $page): JsonResponse
    {
        $request->validate([
            'tool' => ['required', 'in:pen,line,rectangle,circle,arrow,text,sticky_note,image'],
            'data' => ['required', 'array'],
            'color' => ['sometimes', 'nullable', 'string', 'max:16'],
            'thickness' => ['sometimes', 'nullable', 'integer'],
        ]);

        $participant = $this->meetings->participantForGuestSessionAndMeeting($guestSession, $whiteboard->meeting);
        $this->whiteboards->assertCanDraw($whiteboard, $participant->role);

        $object = $this->whiteboards->addObject(
            $page,
            $participant,
            $request->input('tool'),
            $request->input('data'),
            $request->input('color'),
            $request->input('thickness'),
        );

        return response()->json(['success' => true, 'message' => 'Object created.', 'data' => new WhiteboardObjectResource($object), 'errors' => null], 201);
    }

    public function deleteObject(MeetingGuestSession $guestSession, Whiteboard $whiteboard, WhiteboardObject $object): JsonResponse
    {
        $participant = $this->meetings->participantForGuestSessionAndMeeting($guestSession, $whiteboard->meeting);
        $this->whiteboards->assertCanDraw($whiteboard, $participant->role);
        $this->whiteboards->deleteObject($object, $participant);

        return response()->json(['success' => true, 'message' => 'Object deleted.', 'data' => null, 'errors' => null]);
    }

    public function clearPage(MeetingGuestSession $guestSession, Whiteboard $whiteboard, WhiteboardPage $page): JsonResponse
    {
        $participant = $this->meetings->participantForGuestSessionAndMeeting($guestSession, $whiteboard->meeting);
        $this->whiteboards->assertCanDraw($whiteboard, $participant->role);
        $this->whiteboards->clearPage($page, $participant);

        return response()->json(['success' => true, 'message' => 'Page cleared.', 'data' => null, 'errors' => null]);
    }

    public function undo(MeetingGuestSession $guestSession, Whiteboard $whiteboard): JsonResponse
    {
        $participant = $this->meetings->participantForGuestSessionAndMeeting($guestSession, $whiteboard->meeting);
        $this->whiteboards->assertCanDraw($whiteboard, $participant->role);
        $this->whiteboards->undo($whiteboard);

        return response()->json(['success' => true, 'message' => 'Operation completed successfully.', 'data' => null, 'errors' => null]);
    }

    public function redo(MeetingGuestSession $guestSession, Whiteboard $whiteboard): JsonResponse
    {
        $participant = $this->meetings->participantForGuestSessionAndMeeting($guestSession, $whiteboard->meeting);
        $this->whiteboards->assertCanDraw($whiteboard, $participant->role);
        $this->whiteboards->redo($whiteboard);

        return response()->json(['success' => true, 'message' => 'Operation completed successfully.', 'data' => null, 'errors' => null]);
    }

    public function export(Request $request, MeetingGuestSession $guestSession, Whiteboard $whiteboard): JsonResponse
    {
        $request->validate(['media_id' => ['required', 'integer', 'exists:media,id'], 'format' => ['required', 'in:png,jpg,pdf']]);
        $participant = $this->meetings->participantForGuestSessionAndMeeting($guestSession, $whiteboard->meeting);

        $media = Media::findOrFail($request->input('media_id'));
        abort_unless($media->uploader_id === null, 404); // only a file this person uploaded can become the export
        $export = $this->whiteboards->recordExport($whiteboard, $media, $request->input('format'), $participant);

        return response()->json(['success' => true, 'message' => 'Export recorded.', 'data' => ['id' => $export->id, 'media_id' => $media->id, 'format' => $export->format], 'errors' => null], 201);
    }
}
