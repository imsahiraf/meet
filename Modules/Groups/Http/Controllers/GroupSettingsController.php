<?php

namespace Modules\Groups\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Groups\Http\Resources\GroupResource;
use Modules\Groups\Models\Group;
use Modules\Groups\Services\GroupService;

class GroupSettingsController extends Controller
{
    public function __construct(
        private readonly GroupService $groups,
    ) {
    }

    /**
     * RAS-3 API36 - PATCH /groups/{groupId}/settings. Message/invite
     * permission toggles live under the Permissions endpoint (API14) -
     * RAS-3 lists them under both categories, so they're implemented
     * once, there.
     */
    public function update(Request $request, Group $group): JsonResponse
    {
        $this->authorize('manageSettings', $group);

        $data = $request->validate([
            'approval_required' => ['sometimes', 'boolean'],
            'member_limit' => ['sometimes', 'integer', 'min:2', 'max:100000'],
        ]);

        $group = $this->groups->updateSettings($group, $request->user(), $data);

        return response()->json([
            'success' => true,
            'message' => 'Group settings updated.',
            'data' => new GroupResource($group),
            'errors' => null,
        ]);
    }

    /**
     * RAS-3 API37 - POST /groups/{groupId}/mute. Personal preference,
     * mirroring 1:1 chat's per-member mute.
     */
    public function mute(Request $request, Group $group): JsonResponse
    {
        $this->authorize('view', $group);

        $muted = $request->boolean('muted', true);
        $this->groups->toggleMute($group, $request->user(), $muted);

        return response()->json([
            'success' => true,
            'message' => $muted ? 'Group muted.' : 'Group unmuted.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-3 API38 - POST /groups/{groupId}/archive. Personal preference,
     * reusing the existing conversation archive column.
     */
    public function archive(Request $request, Group $group): JsonResponse
    {
        $this->authorize('view', $group);

        $archived = $request->boolean('archived', true);
        $this->groups->toggleArchive($group, $request->user(), $archived);

        return response()->json([
            'success' => true,
            'message' => $archived ? 'Group archived.' : 'Group unarchived.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
