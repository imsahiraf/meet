<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Modules\Admin\Models\Admin;
use Modules\Admin\Models\Permission;
use Modules\Admin\Models\Role;
use Modules\Authentication\Models\User;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\DatabaseSeeder']);
    }

    public function test_default_roles_and_permissions_are_seeded(): void
    {
        $this->assertDatabaseHas('roles', ['name' => 'Super Admin']);
        $this->assertDatabaseHas('roles', ['name' => 'Admin']);
        $this->assertDatabaseHas('roles', ['name' => 'Moderator']);
        $this->assertDatabaseHas('roles', ['name' => 'User']);
        $this->assertDatabaseHas('roles', ['name' => 'Guest']);

        $this->assertSame(10, Permission::count());

        $superAdmin = Role::where('name', 'Super Admin')->first();
        $this->assertSame(10, $superAdmin->permissions()->count());

        $userRole = Role::where('name', 'User')->first();
        $this->assertSame(0, $userRole->permissions()->count());
    }

    public function test_user_has_role_and_has_permission_helpers(): void
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $adminRole = Role::where('name', 'Admin')->first();

        $user->roles()->attach($adminRole->id, ['assigned_at' => now()]);

        $this->assertTrue($user->hasRole('Admin'));
        $this->assertFalse($user->hasRole('Moderator'));
        $this->assertTrue($user->hasPermission('manage_users'));
        $this->assertFalse($user->hasPermission('manage_backups'));
    }

    public function test_super_admin_bypasses_all_gate_checks(): void
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        Admin::create(['user_id' => $user->id, 'admin_level' => 'super', 'is_super_admin' => true]);

        $this->assertTrue(Gate::forUser($user)->allows('anything-not-defined-anywhere'));
    }

    public function test_regular_user_does_not_bypass_gate_checks(): void
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);

        $this->assertFalse(Gate::forUser($user)->allows('anything-not-defined-anywhere'));
    }
}
