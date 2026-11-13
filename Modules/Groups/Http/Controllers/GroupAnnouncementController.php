<?php

namespace Modules\Groups\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Groups\Http\Resources\GroupAnnouncementResource;
use Modules\Groups\Models\Group;
use Modules\Groups\Models\GroupAnnouncement;
use Modules\Groups\Services\GroupContentService;

class GroupAnnouncementController extends Controller
{
    public function __construct(
        private readonly GroupContentService $content,
    ) {
    }

    /**
     * RAS-3 API24 - GET /groups/{groupId}/announcements
     */
    public function index(Group $group): JsonResponse
    {
        $this->authorize('view', $group);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => GroupAnnouncementResource::collection($this->content->listAnnouncements($group)),
            'errors' => null,
        ]);
    }

    /**
     * RAS-3 API21 - POST /groups/{groupId}/announcements
     */
    public function store(Request $request, Group $group): JsonResponse
    {
        $this->authorize('manageAnnouncements', $group);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'announcement' => ['required', 'string', 'max:5000'],
            'is_pinned' => ['sometimes', 'boolean'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $announcement = $this->content->createAnnouncement(
            $group,
            $request->user(),
            $data['title'],
            $data['announcement'],
            $data['is_pinned'] ?? false,
            $data['expires_at'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Announcement published.',
            'data' => new GroupAnnouncementResource($announcement->load('creator')),
            'errors' => null,
        ], 201);
    }

    /**
     * RAS-3 API22 - PATCH /groups/{groupId}/announcements/{announcementId}
     */
    public function update(Request $request, Group $group, GroupAnnouncement $announcement): JsonResponse
    {
        $this->authorize('manageAnnouncements', $group);
        abort_unless($announcement->group_id === $group->id, 404);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:150'],
            'announcement' => ['sometimes', 'string', 'max:5000'],
            'is_pinned' => ['sometimes', 'boolean'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $announcement = $this->content->updateAnnouncement($announcement, $data);

        return response()->json([
            'success' => true,
            'message' => 'Announcement updated.',
            'data' => new GroupAnnouncementResource($announcement),
            'errors' => null,
        ]);
    }

    /**
     * RAS-3 API23 - DELETE /groups/{groupId}/announcements/{announcementId}
     */
    public function destroy(Group $group, GroupAnnouncement $announcement): JsonResponse
    {
        $this->authorize('manageAnnouncements', $group);
        abort_unless($announcement->group_id === $group->id, 404);

        $this->content->deleteAnnouncement($announcement);

        return response()->json([
            'success' => true,
            'message' => 'Announcement deleted.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
