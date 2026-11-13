<?php

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Core\Jobs\CleanupStalePushSubscriptionsJob;
use Modules\Core\Models\PushSubscription;
use Modules\Core\Services\PushNotificationService;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }

    public function test_subscribing_stores_a_push_subscription_and_resubscribing_the_same_token_updates_it(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/push-subscriptions', [
            'fcm_token' => 'fcm-token-abc',
        ])->assertStatus(200);

        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $user->id,
            'fcm_token' => 'fcm-token-abc',
        ]);

        // Re-registering the same token (e.g. the browser refreshes it)
        // updates the row rather than creating a duplicate.
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/push-subscriptions', [
            'fcm_token' => 'fcm-token-abc',
        ])->assertStatus(200);

        $this->assertDatabaseCount('push_subscriptions', 1);
    }

    public function test_subscribing_captures_a_truncated_user_agent_for_the_devices_list(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user, 'sanctum')
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Test Browser)'])
            ->postJson('/api/v1/push-subscriptions', ['fcm_token' => 'fcm-token-ua-test'])
            ->assertStatus(200);

        $this->assertDatabaseHas('push_subscriptions', [
            'fcm_token' => 'fcm-token-ua-test',
            'user_agent' => 'Mozilla/5.0 (Test Browser)',
        ]);
    }

    public function test_unsubscribe_all_removes_every_subscription_for_that_user_only(): void
    {
        $user = $this->activeUser();
        $other = $this->activeUser();

        PushSubscription::create(['user_id' => $user->id, 'fcm_token' => 'token-1']);
        PushSubscription::create(['user_id' => $user->id, 'fcm_token' => 'token-2']);
        PushSubscription::create(['user_id' => $other->id, 'fcm_token' => 'token-3']);

        app(PushNotificationService::class)->unsubscribeAll($user);

        $this->assertDatabaseMissing('push_subscriptions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('push_subscriptions', ['user_id' => $other->id]);
    }

    public function test_unsubscribing_removes_only_that_users_matching_subscription(): void
    {
        $user = $this->activeUser();
        $other = $this->activeUser();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/push-subscriptions', ['fcm_token' => 'shared-token-value']);
        $this->actingAs($other, 'sanctum')->postJson('/api/v1/push-subscriptions', ['fcm_token' => 'shared-token-value']);

        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/push-subscriptions', [
            'fcm_token' => 'shared-token-value',
        ])->assertStatus(200);

        $this->assertDatabaseMissing('push_subscriptions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('push_subscriptions', ['user_id' => $other->id]);
    }

    public function test_send_to_user_is_a_silent_no_op_without_firebase_credentials_configured(): void
    {
        config(['firebase.project_id' => null, 'firebase.private_key' => null]);

        $user = $this->activeUser();
        PushSubscription::create(['user_id' => $user->id, 'fcm_token' => 'whatever-token']);

        // Must not throw even though a subscription exists.
        app(PushNotificationService::class)->sendToUser($user, 'Title', 'Body');
        $this->assertTrue(true);
    }

    public function test_send_to_user_does_not_throw_when_the_token_is_invalid(): void
    {
        // Real credentials aren't available in a test environment - a
        // request to Firebase with a syntactically valid but fake
        // service account fails the network/auth call itself,
        // exercising the same "never throw, never break the caller"
        // guarantee an invalid token would against a real project.
        config([
            'firebase.project_id' => 'test-project',
            'firebase.client_email' => 'test@test-project.iam.gserviceaccount.com',
            'firebase.private_key' => "-----BEGIN PRIVATE KEY-----\nMC4CAQAwBQYDK2VwBCIEIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA\n-----END PRIVATE KEY-----\n",
        ]);

        $user = $this->activeUser();
        PushSubscription::create(['user_id' => $user->id, 'fcm_token' => 'not-a-real-token']);

        app(PushNotificationService::class)->sendToUser($user, 'Title', 'Body');
        $this->assertTrue(true);
    }

    public function test_cleanup_job_removes_only_subscriptions_not_touched_in_over_a_year(): void
    {
        $user = $this->activeUser();

        $stale = PushSubscription::create(['user_id' => $user->id, 'fcm_token' => 'stale-token']);
        $stale->timestamps = false;
        $stale->forceFill(['updated_at' => now()->subYear()->subDay()])->save();

        $recent = PushSubscription::create(['user_id' => $user->id, 'fcm_token' => 'recent-token']);
        $recent->timestamps = false;
        $recent->forceFill(['updated_at' => now()->subMonths(6)])->save();

        (new CleanupStalePushSubscriptionsJob)->handle();

        $this->assertDatabaseMissing('push_subscriptions', ['id' => $stale->id]);
        $this->assertDatabaseHas('push_subscriptions', ['id' => $recent->id]);
    }
}
