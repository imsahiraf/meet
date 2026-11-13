<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Resources\RoleResource;
use Modules\Admin\Models\Permission;
use Modules\Admin\Models\Role;
use Modules\Admin\Services\RoleManagementService;
use Modules\Authentication\Models\User;

class AdminRoleController extends Controller
{
    public function __construct(
        private readonly RoleManagementService $roles,
    ) {
    }

    public function index(): JsonResponse
    {
        $this->authorize('manage_roles');

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => RoleResource::collection($this->roles->list()),
            'errors' => null,
        ]);
    }

    public function permissions(): JsonResponse
    {
        $this->authorize('manage_roles');

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => Permission::orderBy('module')->get(['permission_key', 'permission_name', 'module']),
            'errors' => null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('manage_roles');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:roles,name'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,permission_key'],
        ]);

        $role = $this->roles->create($request->user(), $data['name'], $data['description'] ?? null, $data['permissions'] ?? []);

        return response()->json(['success' => true, 'message' => 'Role created.', 'data' => new RoleResource($role->load('permissions')), 'errors' => null], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $this->authorize('manage_roles');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', 'unique:roles,name,'.$role->id],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,permission_key'],
        ]);

        $role = $this->roles->update($request->user(), $role, $data['name'] ?? null, $data['description'] ?? null, $data['permissions'] ?? null);

        return response()->json(['success' => true, 'message' => 'Role updated.', 'data' => new RoleResource($role->load('permissions')), 'errors' => null]);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->authorize('manage_roles');
        $this->roles->delete($request->user(), $role);

        return response()->json(['success' => true, 'message' => 'Role deleted.', 'data' => null, 'errors' => null]);
    }

    public function clone(Request $request, Role $role): JsonResponse
    {
        $this->authorize('manage_roles');
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'unique:roles,name']]);
        $clone = $this->roles->clone($request->user(), $role, $data['name']);

        return response()->json(['success' => true, 'message' => 'Role cloned.', 'data' => new RoleResource($clone->load('permissions')), 'errors' => null], 201);
    }

    public function assign(Request $request, User $user, Role $role): JsonResponse
    {
        $this->authorize('manage_roles');
        $this->roles->assignToUser($request->user(), $user, $role);

        return response()->json(['success' => true, 'message' => 'Role assigned.', 'data' => null, 'errors' => null]);
    }

    public function revoke(Request $request, User $user, Role $role): JsonResponse
    {
        $this->authorize('manage_roles');
        $this->roles->revokeFromUser($request->user(), $user, $role);

        return response()->json(['success' => true, 'message' => 'Role revoked.', 'data' => null, 'errors' => null]);
    }
}
