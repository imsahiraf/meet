<?php

namespace Modules\Groups\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Groups\Http\Resources\GroupJoinRequestResource;
use Modules\Groups\Models\Group;
use Modules\Groups\Models\GroupJoinRequest;
use Modules\Groups\Services\GroupInviteService;

class GroupJoinRequestController extends Controller
{
    public function __construct(
        private readonly GroupInviteService $invites,
    ) {
    }

    /**
     * Not separately numbered in RAS-3, but needed to build any
     * approve/reject UI on top of API19/API20.
     */
    public function index(Group $group): JsonResponse
    {
        $this->authorize('manageJoinRequests', $group);

        $requests = $group->joinRequests()->with('user')->where('status', 'pending')->latest()->get();

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => GroupJoinRequestResource::collection($requests),
            'errors' => null,
        ]);
    }

    /**
     * RAS-3 API18 - POST /groups/{groupId}/join-request
     */
    public function store(Request $request, Group $group): JsonResponse
    {
        $joinRequest = $this->invites->submitJoinRequest($group, $request->user(), $request->input('message'));

        return response()->json([
            'success' => true,
            'message' => 'Join request submitted.',
            'data' => new GroupJoinRequestResource($joinRequest->load('user')),
            'errors' => null,
        ], 201);
    }

    /**
     * RAS-3 API19 - POST /groups/{groupId}/join-request/{requestId}/approve
     */
    public function approve(Request $request, Group $group, GroupJoinRequest $joinRequest): JsonResponse
    {
        $this->authorize('manageJoinRequests', $group);

        $this->invites->approveJoinRequest($group, $request->user(), $joinRequest);

        return response()->json([
            'success' => true,
            'message' => 'Join request approved.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-3 API20 - POST /groups/{groupId}/join-request/{requestId}/reject
     */
    public function reject(Request $request, Group $group, GroupJoinRequest $joinRequest): JsonResponse
    {
        $this->authorize('manageJoinRequests', $group);

        $this->invites->rejectJoinRequest($group, $request->user(), $joinRequest);

        return response()->json([
            'success' => true,
            'message' => 'Join request rejected.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
