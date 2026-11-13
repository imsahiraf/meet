<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Admin\Models\Admin;
use Modules\Admin\Models\FeatureFlag;
use Modules\Admin\Models\Role;
use Modules\Authentication\Models\User;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\DatabaseSeeder']);
    }

    private function activeUser(): User
    {
        return User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    }

    private function adminUser(bool $superAdmin = false): User
    {
        $user = $this->activeUser();
        Admin::create(['user_id' => $user->id, 'admin_level' => $superAdmin ? 'super' : 'admin', 'is_super_admin' => $superAdmin]);

        if (! $superAdmin) {
            $adminRole = Role::where('name', 'Admin')->first();
            $user->roles()->attach($adminRole->id, ['assigned_at' => now()]);
        }

        return $user;
    }

    public function test_non_admin_cannot_access_any_admin_endpoint(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/dashboard')->assertStatus(403);
    }

    public function test_admin_dashboard_returns_platform_stats(): void
    {
        $admin = $this->adminUser();
        User::factory()->count(3)->create(['status' => 'active', 'email_verified_at' => now()]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/dashboard');
        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(4, $response->json('data.total_users'));
        $this->assertIsArray($response->json('data.warnings'));
    }

    public function test_dashboard_warns_when_php_upload_limits_are_below_the_configured_media_limit(): void
    {
        $admin = $this->adminUser();

        config(['media.max_upload_mb' => 9999]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/dashboard');
        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.warnings'));
        $this->assertStringContainsString('upload_max_filesize', $response->json('data.warnings.0'));
    }

    public function test_admin_can_search_suspend_and_activate_a_user(): void
    {
        $admin = $this->adminUser();
        $target = $this->activeUser();

        $list = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/users?search='.urlencode($target->email));
        $list->assertStatus(200)->assertJsonCount(1, 'data');

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/users/{$target->id}/suspend")->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'status' => 'suspended']);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/users/{$target->id}/activate")->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'status' => 'active']);
    }

    public function test_admin_users_list_can_filter_by_real_vs_load_test_accounts(): void
    {
        $admin = $this->adminUser();
        $real = User::factory()->create(['status' => 'active', 'email_verified_at' => now(), 'email' => 'real.person@example.com']);
        $fake = User::factory()->create(['status' => 'active', 'email_verified_at' => now(), 'email' => 'seeded.person123@mail.meet']);

        $all = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/users?search=person');
        $all->assertStatus(200)->assertJsonCount(2, 'data');
        $this->assertTrue(collect($all->json('data'))->firstWhere('id', $fake->id)['is_load_test_account']);
        $this->assertFalse(collect($all->json('data'))->firstWhere('id', $real->id)['is_load_test_account']);

        $realOnly = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/users?search=person&account_type=real');
        $realOnly->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($real->id, $realOnly->json('data.0.id'));

        $fakeOnly = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/users?search=person&account_type=fake');
        $fakeOnly->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($fake->id, $fakeOnly->json('data.0.id'));
    }

    public function test_suspending_a_user_forcibly_logs_them_out_everywhere(): void
    {
        $admin = $this->adminUser();
        $target = $this->activeUser();
        $token = $target->createToken('test')->plainTextToken;

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/users/{$target->id}/suspend")->assertStatus(200);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_can_soft_delete_and_restore_a_user(): void
    {
        $admin = $this->adminUser();
        $target = $this->activeUser();

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/admin/users/{$target->id}")->assertStatus(200);
        $this->assertSoftDeleted('users', ['id' => $target->id]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/users/{$target->id}/restore")->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'deleted_at' => null]);
    }

    public function test_admin_without_manage_users_permission_is_forbidden(): void
    {
        $moderator = $this->activeUser();
        Admin::create(['user_id' => $moderator->id, 'admin_level' => 'moderator', 'is_super_admin' => false]);
        $modRole = Role::where('name', 'Moderator')->first();
        $moderator->roles()->attach($modRole->id, ['assigned_at' => now()]);

        $target = $this->activeUser();
        $this->actingAs($moderator, 'sanctum')->postJson("/api/v1/admin/users/{$target->id}/suspend")->assertStatus(403);
    }

    public function test_super_admin_bypasses_every_permission_check(): void
    {
        $superAdmin = $this->adminUser(superAdmin: true);
        $target = $this->activeUser();

        $this->actingAs($superAdmin, 'sanctum')->postJson("/api/v1/admin/users/{$target->id}/suspend")->assertStatus(200);
        $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/admin/logs/audit')->assertStatus(200);
    }

    public function test_role_crud_and_the_super_admin_role_cannot_be_deleted(): void
    {
        $admin = $this->adminUser();

        $create = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/roles', [
            'name' => 'Support Agent',
            'description' => 'Read-only support access',
            'permissions' => ['view_analytics'],
        ]);
        $create->assertStatus(201);
        $roleId = $create->json('data.id');

        $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/admin/roles/{$roleId}", ['description' => 'Updated'])->assertStatus(200);

        $superAdminRole = Role::where('name', 'Super Admin')->first();
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/admin/roles/{$superAdminRole->id}")->assertStatus(422);

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/admin/roles/{$roleId}")->assertStatus(200);
        $this->assertDatabaseMissing('roles', ['id' => $roleId]);
    }

    public function test_assigning_and_revoking_a_role_from_a_user(): void
    {
        $admin = $this->adminUser();
        $target = $this->activeUser();
        $role = Role::where('name', 'Moderator')->first();

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/users/{$target->id}/roles/{$role->id}")->assertStatus(200);
        $this->assertTrue($target->fresh()->hasRole('Moderator'));

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/admin/users/{$target->id}/roles/{$role->id}")->assertStatus(200);
        $this->assertFalse($target->fresh()->hasRole('Moderator'));
    }

    public function test_general_settings_can_be_read_and_updated(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/settings/general')
            ->assertStatus(200)
            ->assertJsonPath('data.session_timeout_minutes', 120);

        $this->actingAs($admin, 'sanctum')->patchJson('/api/v1/admin/settings/general', [
            'settings' => ['session_timeout_minutes' => 60],
        ])->assertStatus(200)->assertJsonPath('data.session_timeout_minutes', 60);
    }

    public function test_branding_settings_can_be_updated(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin, 'sanctum')->patchJson('/api/v1/admin/settings/branding', [
            'app_name' => 'Meet Enterprise',
            'theme_color' => '#111827',
        ])->assertStatus(200)->assertJsonPath('data.app_name', 'Meet Enterprise');

        $this->assertDatabaseHas('branding_settings', ['app_name' => 'Meet Enterprise']);
    }

    public function test_smtp_password_is_never_exposed_in_the_get_response(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin, 'sanctum')->patchJson('/api/v1/admin/settings/smtp', [
            'host' => 'smtp.example.test',
            'password' => 'super-secret',
        ])->assertStatus(200);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/settings/smtp');
        $response->assertStatus(200)->assertJsonPath('data.password_set', true);
        $this->assertStringNotContainsString('super-secret', $response->getContent());

        $this->assertDatabaseMissing('smtp_settings', ['password' => 'super-secret']);
    }

    public function test_feature_flags_can_be_toggled(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin, 'sanctum')->patchJson('/api/v1/admin/settings/features/whiteboard', ['is_enabled' => false])->assertStatus(200);
        $this->assertFalse(FeatureFlag::enabled('whiteboard'));
    }

    public function test_maintenance_mode_can_be_toggled(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/system/maintenance', [
            'enabled' => true,
            'message' => 'Back soon',
        ])->assertStatus(200);

        $this->assertDatabaseHas('settings', ['setting_key' => 'maintenance_mode_enabled', 'setting_value' => '1']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/system/maintenance', ['enabled' => false])->assertStatus(200);
    }

    public function test_admin_actions_are_recorded_in_the_audit_log(): void
    {
        $admin = $this->adminUser();
        $target = $this->activeUser();

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/users/{$target->id}/suspend");

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'module' => 'admin',
            'action' => 'user_suspended',
            'record_id' => $target->id,
        ]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/logs/audit')->assertStatus(200)->assertJsonPath('meta.total', 1);
    }
}
