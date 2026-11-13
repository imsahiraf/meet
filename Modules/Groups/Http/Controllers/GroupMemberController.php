<?php

namespace Modules\Groups\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Groups\Http\Requests\AddGroupMembersRequest;
use Modules\Groups\Http\Resources\GroupMemberResource;
use Modules\Groups\Models\Group;
use Modules\Groups\Services\GroupService;

class GroupMemberController extends Controller
{
    public function __construct(
        private readonly GroupService $groups,
    ) {
    }

    /**
     * RAS-3 API9 - GET /groups/{groupId}/members
     */
    public function index(Request $request, Group $group): JsonResponse
    {
        $this->authorize('view', $group);

        $query = $group->activeMembers()->with(['user', 'role']);

        if ($search = $request->string('q')->trim()->toString()) {
            $query->whereHas('user', fn ($q) => $q->where('full_name', 'like', "%{$search}%"));
        }

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => GroupMemberResource::collection($query->get()),
            'errors' => null,
        ]);
    }

    /**
     * RAS-3 API6 - POST /groups/{groupId}/members
     */
    public function store(AddGroupMembersRequest $request, Group $group): JsonResponse
    {
        $this->authorize('manageMembers', $group);

        $this->groups->addMembers($group, $request->user(), $request->input('user_ids'));

        return response()->json([
            'success' => true,
            'message' => 'Members added.',
            'data' => null,
            'errors' => null,
        ], 201);
    }

    /**
     * RAS-3 API7 - DELETE /groups/{groupId}/members/{userId}
     */
    public function destroy(Request $request, Group $group, int $userId): JsonResponse
    {
        $this->authorize('manageMembers', $group);

        $this->groups->removeMember($group, $request->user(), $userId);

        return response()->json([
            'success' => true,
            'message' => 'Member removed.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-3 API8 - POST /groups/{groupId}/leave
     */
    public function leave(Request $request, Group $group): JsonResponse
    {
        $this->authorize('view', $group);

        $this->groups->leave($group, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'You left the group.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-3 API39 - POST /groups/{groupId}/bulk-remove
     */
    public function bulkRemove(Request $request, Group $group): JsonResponse
    {
        $this->authorize('manageMembers', $group);

        $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer'],
        ]);

        $this->groups->bulkRemoveMembers($group, $request->user(), $request->input('user_ids'));

        return response()->json([
            'success' => true,
            'message' => 'Members removed.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * FR-072/FR-077 - not a distinct RAS-3 endpoint, but required for
     * "Owner leaves -> must transfer ownership first" and "Transfer
     * Ownership" to be reachable at all; kept separate from plain role
     * assignment since it's a two-sided operation (old owner -> Admin).
     */
    public function transferOwnership(Request $request, Group $group): JsonResponse
    {
        $this->authorize('manageRoles', $group);

        $request->validate(['user_id' => ['required', 'integer']]);

        $this->groups->transferOwnership($group, $request->user(), (int) $request->input('user_id'));

        return response()->json([
            'success' => true,
            'message' => 'Ownership transferred.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
