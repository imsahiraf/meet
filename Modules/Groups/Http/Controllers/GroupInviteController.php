<?php

namespace Modules\Groups\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Groups\Http\Resources\GroupInviteResource;
use Modules\Groups\Models\Group;
use Modules\Groups\Services\GroupInviteService;

class GroupInviteController extends Controller
{
    public function __construct(
        private readonly GroupInviteService $invites,
    ) {
    }

    /**
     * RAS-3 API15 - POST /groups/{groupId}/invite
     */
    public function store(Request $request, Group $group): JsonResponse
    {
        $this->authorize('manageInvites', $group);

        $request->validate([
            'invited_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'expires_in_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:720'],
        ]);

        $invite = $this->invites->generateInvite(
            $group,
            $request->user(),
            $request->input('invited_user_id'),
            $request->input('expires_in_hours'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Invite created.',
            'data' => new GroupInviteResource($invite),
            'errors' => null,
        ], 201);
    }

    /**
     * RAS-3 API17 - DELETE /groups/{groupId}/invite
     */
    public function destroy(Request $request, Group $group): JsonResponse
    {
        $this->authorize('manageInvites', $group);

        $this->invites->revokeInvite($group, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Invite revoked.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-3 API16 - POST /groups/join
     */
    public function join(Request $request): JsonResponse
    {
        $request->validate(['invite_token' => ['required', 'string']]);

        $group = $this->invites->joinViaInvite($request->user(), $request->string('invite_token'));

        return response()->json([
            'success' => true,
            'message' => 'You joined the group.',
            'data' => ['group_id' => $group->id],
            'errors' => null,
        ]);
    }
}
