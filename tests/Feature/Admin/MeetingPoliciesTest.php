<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Admin\Models\Admin;
use Modules\Admin\Models\FeatureFlag;
use Modules\Authentication\Models\User;
use Tests\TestCase;

/** Meeting policies: switched-off features are refused by the server; the audit log downloads as CSV. */
class MeetingPoliciesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\DatabaseSeeder']);
    }

    public function test_switched_off_features_are_refused(): void
    {
        $this->assertTrue(FeatureFlag::where('feature_key', 'recording')->exists(), 'policy flags are seeded by the migration');
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        foreach (['voice_calls' => '/api/v1/calls/voice/start', 'video_calls' => '/api/v1/calls/video/start'] as $key => $url) {
            FeatureFlag::where('feature_key', $key)->update(['is_enabled' => false]);
            $this->actingAs($user, 'sanctum')->postJson($url, [])->assertStatus(403)->assertJsonPath('errors.feature.0', $key);
        }
        $m = \Modules\Meetings\Models\Meeting::create(['organizer_id' => $user->id, 'meeting_code' => '987654321', 'title' => 'Policy', 'meeting_type' => 'instant',
            'status' => 'active', 'actual_start' => now()]);
        FeatureFlag::where('feature_key', 'recording')->update(['is_enabled' => false]);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/meetings/' . $m->getRouteKey() . '/recording', [])->assertStatus(403);
        FeatureFlag::where('feature_key', 'guest_mode')->update(['is_enabled' => false]);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/meetings/' . $m->getRouteKey() . '/guest', ['name' => 'Guest'])->assertStatus(403);
        $this->get('/meetings/guest/new')->assertStatus(403);
        // switched on again: the request reaches the controller (validation / not found instead of 403)
        FeatureFlag::query()->update(['is_enabled' => true]);
        $this->assertNotSame(403, $this->actingAs($user, 'sanctum')->postJson('/api/v1/calls/voice/start', [])->status());
    }

    public function test_audit_log_csv_for_admins_only(): void
    {
        $admin = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        Admin::create(['user_id' => $admin->id, 'admin_level' => 'super', 'is_super_admin' => true]);
        \Modules\Admin\Models\AuditLog::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $admin->id, 'module' => 'admin', 'action' => 'policy_test',
            'ip_address' => '10.1.2.3', 'created_at' => now()]);
        $csv = $this->actingAs($admin, 'sanctum')->get('/api/v1/admin/logs/audit.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('policy_test', $csv);
        $this->assertStringContainsString('10.1.2.3', $csv);
        $plain = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $this->assertContains($this->actingAs($plain, 'sanctum')->get('/api/v1/admin/logs/audit.csv')->status(), [302, 403]);
    }
}
