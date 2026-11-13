<?php

namespace Modules\Groups\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Groups\Http\Requests\CreateGroupRequest;
use Modules\Groups\Http\Requests\UpdateGroupRequest;
use Modules\Groups\Http\Resources\GroupResource;
use Modules\Groups\Models\Group;
use Modules\Groups\Services\GroupService;

class GroupController extends Controller
{
    public function __construct(
        private readonly GroupService $groups,
    ) {
    }

    /**
     * RAS-3 API1 - POST /groups
     */
    public function store(CreateGroupRequest $request): JsonResponse
    {
        $group = $this->groups->create(
            $request->user(),
            $request->string('group_name'),
            $request->input('description'),
            $request->input('avatar'),
            $request->input('members'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Group created.',
            'data' => new GroupResource($group->fresh()),
            'errors' => null,
        ], 201);
    }

    /**
     * RAS-3 API2 - GET /groups
     */
    public function index(Request $request): JsonResponse
    {
        $groups = $this->groups->listForUser($request->user(), $request->string('q')->trim()->toString() ?: null);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => GroupResource::collection($groups),
            'errors' => null,
        ]);
    }

    /**
     * RAS-3 API3 - GET /groups/{groupId}
     */
    public function show(Request $request, Group $group): JsonResponse
    {
        $this->authorize('view', $group);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => new GroupResource($group->loadCount('activeMembers as member_count')->load('conversation')),
            'errors' => null,
        ]);
    }

    /**
     * RAS-3 API4 - PUT /groups/{groupId}
     */
    public function update(UpdateGroupRequest $request, Group $group): JsonResponse
    {
        $this->authorize('update', $group);

        $group = $this->groups->update($group, $request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Group updated.',
            'data' => new GroupResource($group),
            'errors' => null,
        ]);
    }

    /**
     * RAS-3 API5 - DELETE /groups/{groupId}
     */
    public function destroy(Request $request, Group $group): JsonResponse
    {
        $this->authorize('delete', $group);

        $this->groups->delete($group, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Group deleted.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
