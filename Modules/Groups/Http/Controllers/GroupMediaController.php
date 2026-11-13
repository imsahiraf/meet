<?php

namespace Modules\Groups\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Groups\Models\Group;
use Modules\Groups\Services\GroupContentService;

class GroupMediaController extends Controller
{
    public function __construct(
        private readonly GroupContentService $content,
    ) {
    }

    /**
     * RAS-3 API33 - GET /groups/{groupId}/media
     */
    public function media(Group $group): JsonResponse
    {
        $this->authorize('view', $group);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => $this->content->media($group)->map(fn ($m) => [
                'id' => $m->id, 'type' => $m->message_type, 'sender' => $m->sender->full_name, 'created_at' => $m->created_at,
            ]),
            'errors' => null,
        ]);
    }

    /**
     * RAS-3 API34 - GET /groups/{groupId}/links
     */
    public function links(Group $group): JsonResponse
    {
        $this->authorize('view', $group);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => $this->content->links($group)->map(fn ($m) => [
                'id' => $m->id, 'body' => $m->body, 'sender' => $m->sender->full_name, 'created_at' => $m->created_at,
            ]),
            'errors' => null,
        ]);
    }

    /**
     * RAS-3 API35 - GET /groups/{groupId}/documents
     */
    public function documents(Group $group): JsonResponse
    {
        $this->authorize('view', $group);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => $this->content->documents($group)->map(fn ($m) => [
                'id' => $m->id, 'sender' => $m->sender->full_name, 'created_at' => $m->created_at,
            ]),
            'errors' => null,
        ]);
    }
}
