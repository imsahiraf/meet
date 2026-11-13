<?php

namespace Modules\Whiteboard\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Media\Models\Media;
use Modules\Meetings\Models\Meeting;
use Modules\Meetings\Services\MeetingService;
use Modules\Whiteboard\Http\Resources\WhiteboardObjectResource;
use Modules\Whiteboard\Http\Resources\WhiteboardResource;
use Modules\Whiteboard\Models\Whiteboard;
use Modules\Whiteboard\Models\WhiteboardObject;
use Modules\Whiteboard\Models\WhiteboardPage;
use Modules\Whiteboard\Services\WhiteboardService;

class WhiteboardController extends Controller
{
    public function __construct(
        private readonly WhiteboardService $whiteboards,
        private readonly MeetingService $meetings,
    ) {
    }

    /**
     * FR-141 - open (or resume) a meeting's whiteboard.
     */
    public function open(Request $request, Meeting $meeting): JsonResponse
    {
        $participant = $this->meetings->participantFor($meeting, $request->user());
        $whiteboard = $this->whiteboards->open($meeting, $participant);

        return response()->json([
            'success' => true,
            'message' => 'Whiteboard opened.',
            'data' => new WhiteboardResource($whiteboard->load('pages')),
            'errors' => null,
        ], 201);
    }

    public function show(Request $request, Whiteboard $whiteboard): JsonResponse
    {
        $this->meetings->participantFor($whiteboard->meeting, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => new WhiteboardResource($whiteboard->load('pages')),
            'errors' => null,
        ]);
    }

    public function setPermission(Request $request, Whiteboard $whiteboard): JsonResponse
    {
        $request->validate(['permission_mode' => ['required', 'in:host_only,host_and_co_host,all_participants,view_only']]);
        $participant = $this->meetings->participantFor($whiteboard->meeting, $request->user());

        if (! $participant->isHostOrCoHost()) {
            return response()->json(['success' => false, 'message' => 'Only the host or co-host may change whiteboard permissions.', 'data' => null, 'errors' => null], 403);
        }

        $whiteboard = $this->whiteboards->setPermission($whiteboard, $request->input('permission_mode'));

        return response()->json(['success' => true, 'message' => 'Operation completed successfully.', 'data' => new WhiteboardResource($whiteboard), 'errors' => null]);
    }

    public function addPage(Request $request, Whiteboard $whiteboard): JsonResponse
    {
        $this->meetings->participantFor($whiteboard->meeting, $request->user());
        $page = $this->whiteboards->addPage($whiteboard, $request->input('name'));

        return response()->json(['success' => true, 'message' => 'Page added.', 'data' => ['id' => $page->id, 'page_number' => $page->page_number, 'name' => $page->name], 'errors' => null], 201);
    }

    public function deletePage(Request $request, Whiteboard $whiteboard, WhiteboardPage $page): JsonResponse
    {
        $this->meetings->participantFor($whiteboard->meeting, $request->user());
        $this->whiteboards->deletePage($whiteboard, $page);

        return response()->json(['success' => true, 'message' => 'Page deleted.', 'data' => null, 'errors' => null]);
    }

    public function objects(Request $request, Whiteboard $whiteboard, WhiteboardPage $page): JsonResponse
    {
        $this->meetings->participantFor($whiteboard->meeting, $request->user());

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

    public function storeObject(Request $request, Whiteboard $whiteboard, WhiteboardPage $page): JsonResponse
    {
        $request->validate([
            'tool' => ['required', 'in:pen,line,rectangle,circle,arrow,text,sticky_note,image'],
            'data' => ['required', 'array'],
            'color' => ['sometimes', 'nullable', 'string', 'max:16'],
            'thickness' => ['sometimes', 'nullable', 'integer'],
        ]);

        $participant = $this->meetings->participantFor($whiteboard->meeting, $request->user());
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

    public function deleteObject(Request $request, Whiteboard $whiteboard, WhiteboardObject $object): JsonResponse
    {
        $participant = $this->meetings->participantFor($whiteboard->meeting, $request->user());
        $this->whiteboards->assertCanDraw($whiteboard, $participant->role);
        $this->whiteboards->deleteObject($object, $participant);

        return response()->json(['success' => true, 'message' => 'Object deleted.', 'data' => null, 'errors' => null]);
    }

    public function clearPage(Request $request, Whiteboard $whiteboard, WhiteboardPage $page): JsonResponse
    {
        $participant = $this->meetings->participantFor($whiteboard->meeting, $request->user());
        $this->whiteboards->assertCanDraw($whiteboard, $participant->role);
        $this->whiteboards->clearPage($page, $participant);

        return response()->json(['success' => true, 'message' => 'Page cleared.', 'data' => null, 'errors' => null]);
    }

    public function undo(Request $request, Whiteboard $whiteboard): JsonResponse
    {
        $participant = $this->meetings->participantFor($whiteboard->meeting, $request->user());
        $this->whiteboards->assertCanDraw($whiteboard, $participant->role);
        $this->whiteboards->undo($whiteboard);

        return response()->json(['success' => true, 'message' => 'Operation completed successfully.', 'data' => null, 'errors' => null]);
    }

    public function redo(Request $request, Whiteboard $whiteboard): JsonResponse
    {
        $participant = $this->meetings->participantFor($whiteboard->meeting, $request->user());
        $this->whiteboards->assertCanDraw($whiteboard, $participant->role);
        $this->whiteboards->redo($whiteboard);

        return response()->json(['success' => true, 'message' => 'Operation completed successfully.', 'data' => null, 'errors' => null]);
    }

    /**
     * FR-151 Export - client renders the canvas to a blob and uploads
     * it through the Media engine first; this just records the
     * pointer against media_id.
     */
    public function export(Request $request, Whiteboard $whiteboard): JsonResponse
    {
        $request->validate(['media_id' => ['required', 'integer', 'exists:media,id'], 'format' => ['required', 'in:png,jpg,pdf']]);
        $participant = $this->meetings->participantFor($whiteboard->meeting, $request->user());

        $media = Media::findOrFail($request->input('media_id'));
        abort_unless((int) $media->uploader_id === (int) $request->user()->id, 404); // only a file this person uploaded can become the export
        $export = $this->whiteboards->recordExport($whiteboard, $media, $request->input('format'), $participant);

        return response()->json(['success' => true, 'message' => 'Export recorded.', 'data' => ['id' => $export->id, 'media_id' => $media->id, 'format' => $export->format], 'errors' => null], 201);
    }
}
