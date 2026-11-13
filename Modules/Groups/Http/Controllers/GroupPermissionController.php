<?php

namespace Modules\Groups\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Groups\Models\Group;
use Modules\Groups\Services\GroupService;

class GroupPermissionController extends Controller
{
    public function __construct(
        private readonly GroupService $groups,
    ) {
    }

    /**
     * RAS-3 API13 - GET /groups/{groupId}/permissions
     */
    public function index(Group $group): JsonResponse
    {
        $this->authorize('view', $group);

        $roles = $group->roles()->with('permissions')->get()->map(fn ($role) => [
            'role_id' => $role->id,
            'role_name' => $role->role_name,
            'is_system' => $role->is_system,
            'permissions' => $role->permissions->mapWithKeys(fn ($p) => [$p->permission_key => $p->is_allowed]),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => [
                'group_settings' => [
                    'admin_only_messaging' => $group->admin_only_messaging,
                    'admin_only_edit_info' => $group->admin_only_edit_info,
                    'admin_only_invite' => $group->admin_only_invite,
                    'admin_only_pin' => $group->admin_only_pin,
                    'allow_member_reactions' => $group->allow_member_reactions,
                    'allow_member_file_sharing' => $group->allow_member_file_sharing,
                ],
                'roles' => $roles,
            ],
            'errors' => null,
        ]);
    }

    /**
     * RAS-3 API14 - PATCH /groups/{groupId}/permissions
     */
    public function update(Request $request, Group $group): JsonResponse
    {
        $this->authorize('managePermissions', $group);

        $data = $request->validate([
            'admin_only_messaging' => ['sometimes', 'boolean'],
            'admin_only_edit_info' => ['sometimes', 'boolean'],
            'admin_only_invite' => ['sometimes', 'boolean'],
            'admin_only_pin' => ['sometimes', 'boolean'],
            'allow_member_reactions' => ['sometimes', 'boolean'],
            'allow_member_file_sharing' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['array'],
        ]);

        $group = $this->groups->updatePermissions(
            $group,
            $request->user(),
            $data,
            $data['roles'] ?? [],
        );

        return response()->json([
            'success' => true,
            'message' => 'Permissions updated.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
