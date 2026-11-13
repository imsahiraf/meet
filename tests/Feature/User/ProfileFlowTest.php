<?php

namespace Tests\Feature\User;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Tests\TestCase;

class ProfileFlowTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }

    public function test_get_profile_bundle(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/users/profile')
            ->assertStatus(200)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonStructure(['data' => ['profile', 'preferences', 'privacy', 'presence']]);
    }

    public function test_update_profile(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/profile', [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'bio' => 'Hello world',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.first_name', 'Jane');

        $this->assertDatabaseHas('user_profiles', ['user_id' => $user->id, 'first_name' => 'Jane', 'bio' => 'Hello world']);
    }

    public function test_update_preferences(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/users/preferences', ['theme' => 'dark', 'auto_download_photos' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.theme', 'dark');

        $this->assertDatabaseHas('user_preferences', ['user_id' => $user->id, 'theme' => 'dark', 'auto_download_photos' => true]);
    }

    public function test_update_privacy(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/users/privacy', ['last_seen_visibility' => 'nobody', 'read_receipts_enabled' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.last_seen_visibility', 'nobody');

        $this->assertDatabaseHas('user_privacy', ['user_id' => $user->id, 'last_seen_visibility' => 'nobody', 'read_receipts_enabled' => false]);
    }

    public function test_update_presence_heartbeat(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/users/presence', ['presence_status' => 'busy'])
            ->assertStatus(200)
            ->assertJsonPath('data.presence_status', 'busy');

        $this->assertDatabaseHas('user_presence', ['user_id' => $user->id, 'presence_status' => 'busy']);
    }

    public function test_presence_rejects_invalid_status(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/users/presence', ['presence_status' => 'not-a-real-status'])
            ->assertStatus(422);
    }

    public function test_list_and_revoke_active_devices(): void
    {
        $user = $this->activeUser(['password' => bcrypt('DevicePass1!')]);

        $login = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'DevicePass1!',
        ]);
        $token = $login->json('data.token');

        $list = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/users/devices');
        $list->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertTrue($list->json('data.0.is_current_device'));

        $deviceUuid = $list->json('data.0.uuid');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/users/devices/{$deviceUuid}")
            ->assertStatus(200);

        $this->assertDatabaseHas('user_sessions', ['uuid' => $deviceUuid, 'status' => 'logged_out']);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/users/profile')->assertStatus(401);
    }
}
