<?php

namespace Modules\Groups\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Groups\Http\Requests\AssignGroupRoleRequest;
use Modules\Groups\Models\Group;
use Modules\Groups\Models\GroupRole;
use Modules\Groups\Services\GroupService;

class GroupRoleController extends Controller
{
    public function __construct(
        private readonly GroupService $groups,
    ) {
    }

    /**
     * RAS-3 API10 - POST /groups/{groupId}/roles - assigns an existing
     * role to a member (the four roles are seeded per group; see
     * GroupRepository::createDefaultRoles).
     */
    public function store(AssignGroupRoleRequest $request, Group $group): JsonResponse
    {
        $this->authorize('manageRoles', $group);

        $member = $this->groups->assignRole(
            $group,
            $request->user(),
            (int) $request->input('member_id'),
            (int) $request->input('role_id'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Role assigned.',
            'data' => ['user_id' => $member->user_id, 'role' => $member->role->role_name],
            'errors' => null,
        ]);
    }

    /**
     * RAS-3 API11 - PATCH /groups/{groupId}/roles/{roleId}. System roles
     * (Owner/Admin/Moderator/Member) keep their name; only the
     * description is editable.
     */
    public function update(Request $request, Group $group, GroupRole $groupRole): JsonResponse
    {
        $this->authorize('manageRoles', $group);
        abort_unless($groupRole->group_id === $group->id, 404);

        $request->validate(['description' => ['nullable', 'string', 'max:255']]);
        $groupRole->update(['description' => $request->input('description')]);

        return response()->json([
            'success' => true,
            'message' => 'Role updated.',
            'data' => ['id' => $groupRole->id, 'description' => $groupRole->description],
            'errors' => null,
        ]);
    }

    /**
     * RAS-3 API12 - DELETE /groups/{groupId}/roles/{roleId}. System
     * roles cannot be deleted; this project doesn't expose custom role
     * creation (RAS-3 only documents assign/update/remove, not create),
     * so this mainly guards against deleting a role members depend on.
     */
    public function destroy(Group $group, GroupRole $groupRole): JsonResponse
    {
        $this->authorize('manageRoles', $group);
        abort_unless($groupRole->group_id === $group->id, 404);
        abort_if($groupRole->is_system, 403, 'System roles cannot be removed.');

        $groupRole->delete();

        return response()->json([
            'success' => true,
            'message' => 'Role removed.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
