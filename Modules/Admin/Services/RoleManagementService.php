<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Collection;
use Modules\Admin\Exceptions\SuperAdminRoleImmutableException;
use Modules\Admin\Models\Permission;
use Modules\Admin\Models\Role;
use Modules\Authentication\Models\User;

/**
 * FR-198 Role Management. Business rule: the Super Admin role cannot
 * be deleted.
 */
class RoleManagementService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {
    }

    public function list(): Collection
    {
        return Role::withCount('users')->with('permissions')->get();
    }

    public function create(User $actor, string $name, ?string $description, array $permissionKeys): Role
    {
        $role = Role::create(['name' => $name, 'description' => $description, 'is_system' => false]);
        $this->syncPermissions($role, $permissionKeys);
        $this->audit->log($actor, 'admin', 'role_created', Role::class, $role->id, null, ['name' => $name]);

        return $role;
    }

    public function update(User $actor, Role $role, ?string $name, ?string $description, ?array $permissionKeys): Role
    {
        $old = $role->only(['name', 'description']);
        $role->fill(array_filter(['name' => $name, 'description' => $description], fn ($v) => $v !== null));
        $role->save();

        if ($permissionKeys !== null) {
            $this->syncPermissions($role, $permissionKeys);
        }

        $this->audit->log($actor, 'admin', 'role_updated', Role::class, $role->id, $old, $role->only(['name', 'description']));

        return $role;
    }

    public function delete(User $actor, Role $role): void
    {
        if ($role->name === 'Super Admin') {
            throw new SuperAdminRoleImmutableException();
        }

        $this->audit->log($actor, 'admin', 'role_deleted', Role::class, $role->id, ['name' => $role->name]);
        $role->delete();
    }

    public function clone(User $actor, Role $role, string $newName): Role
    {
        $clone = Role::create(['name' => $newName, 'description' => $role->description, 'is_system' => false]);
        $clone->permissions()->sync($role->permissions()->pluck('permissions.id'));
        $this->audit->log($actor, 'admin', 'role_cloned', Role::class, $clone->id, null, ['cloned_from' => $role->id]);

        return $clone;
    }

    public function assignToUser(User $actor, User $target, Role $role): void
    {
        $target->roles()->syncWithoutDetaching([$role->id => ['assigned_by' => $actor->id, 'assigned_at' => now()]]);
        $this->audit->log($actor, 'admin', 'role_assigned', User::class, $target->id, null, ['role' => $role->name]);
    }

    public function revokeFromUser(User $actor, User $target, Role $role): void
    {
        $target->roles()->detach($role->id);
        $this->audit->log($actor, 'admin', 'role_revoked', User::class, $target->id, null, ['role' => $role->name]);
    }

    private function syncPermissions(Role $role, array $permissionKeys): void
    {
        $ids = Permission::whereIn('permission_key', $permissionKeys)->pluck('id');
        $role->permissions()->sync($ids);
    }
}
