<?php

namespace Modules\Groups\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Groups\Http\Resources\GroupEventResource;
use Modules\Groups\Models\Group;
use Modules\Groups\Models\GroupEvent;
use Modules\Groups\Services\GroupContentService;

class GroupEventController extends Controller
{
    public function __construct(
        private readonly GroupContentService $content,
    ) {
    }

    /**
     * RAS-3 API30 - GET /groups/{groupId}/events
     */
    public function index(Group $group): JsonResponse
    {
        $this->authorize('view', $group);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => GroupEventResource::collection($this->content->listEvents($group)),
            'errors' => null,
        ]);
    }

    /**
     * RAS-3 API29 - POST /groups/{groupId}/events
     */
    public function store(Request $request, Group $group): JsonResponse
    {
        $this->authorize('manageEvents', $group);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'event_start' => ['required', 'date'],
            'event_end' => ['sometimes', 'nullable', 'date', 'after_or_equal:event_start'],
        ]);

        $event = $this->content->createEvent(
            $group,
            $request->user(),
            $data['title'],
            $data['description'] ?? null,
            $data['event_start'],
            $data['event_end'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Event created.',
            'data' => new GroupEventResource($event),
            'errors' => null,
        ], 201);
    }

    /**
     * RAS-3 API31 - PATCH /groups/{groupId}/events/{eventId}
     */
    public function update(Request $request, Group $group, GroupEvent $event): JsonResponse
    {
        $this->authorize('manageEvents', $group);
        abort_unless($event->group_id === $group->id, 404);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'event_start' => ['sometimes', 'date'],
            'event_end' => ['sometimes', 'nullable', 'date'],
        ]);

        $event = $this->content->updateEvent($event, $data);

        return response()->json([
            'success' => true,
            'message' => 'Event updated.',
            'data' => new GroupEventResource($event),
            'errors' => null,
        ]);
    }

    /**
     * RAS-3 API32 - DELETE /groups/{groupId}/events/{eventId}
     */
    public function destroy(Group $group, GroupEvent $event): JsonResponse
    {
        $this->authorize('manageEvents', $group);
        abort_unless($event->group_id === $group->id, 404);

        $this->content->deleteEvent($event);

        return response()->json([
            'success' => true,
            'message' => 'Event deleted.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
