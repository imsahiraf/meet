<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Events\UserRegistered;
use Modules\Authentication\Listeners\CreateDefaultUserRecords;
use Modules\Authentication\Models\User;
use Modules\Core\Models\PushSubscription;
use Tests\TestCase;

/**
 * Exercises the Settings page's real, session-based routes (password
 * change, self-service deactivation) end-to-end - the API tests never
 * touch these since they're web-only (no /api equivalent exists).
 */
class WebProfileFlowTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));

        (new CreateDefaultUserRecords)->handle(new UserRegistered($user, '000000'));

        return $user;
    }

    /**
     * Settings became a WhatsApp-style hub of category links instead of
     * one long page - each section is now its own route/view.
     */
    public function test_settings_hub_links_to_every_category(): void
    {
        $user = $this->activeUser();

        $response = $this->actingAs($user, 'web')->get(route('profile.edit'));
        $response->assertStatus(200);
        foreach (['profile.info', 'profile.preferences.show', 'profile.notifications', 'profile.chat-defaults', 'profile.privacy.show', 'profile.security'] as $routeName) {
            $response->assertSee(route($routeName), false);
        }
    }

    public function test_settings_page_prefills_profile_preferences_and_privacy(): void
    {
        $user = $this->activeUser();
        $user->profile()->update(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $user->preferences()->update(['theme' => 'dark', 'timezone' => 'Asia/Kolkata']);

        $this->actingAs($user, 'web')
            ->get(route('profile.info'))
            ->assertStatus(200)
            ->assertSee('value="Ada"', false)
            ->assertSee('value="Lovelace"', false);

        $this->actingAs($user, 'web')
            ->get(route('profile.preferences.show'))
            ->assertStatus(200)
            ->assertSee('Asia/Kolkata', false);
    }

    public function test_change_password_requires_correct_current_password(): void
    {
        $user = $this->activeUser(['password' => bcrypt('CurrentPass1!')]);

        $this->actingAs($user, 'web')
            ->put(route('profile.password'), [
                'current_password' => 'WrongPass1!',
                'new_password' => 'BrandNewPass1!',
                'new_password_confirmation' => 'BrandNewPass1!',
            ])->assertSessionHasErrors('current_password');

        $this->actingAs($user, 'web')
            ->put(route('profile.password'), [
                'current_password' => 'CurrentPass1!',
                'new_password' => 'BrandNewPass1!',
                'new_password_confirmation' => 'BrandNewPass1!',
            ])->assertSessionHasNoErrors();

        $this->post('/login', ['login' => $user->email, 'password' => 'BrandNewPass1!'])
            ->assertRedirect(route('chat.index'));
    }

    public function test_deactivate_account_requires_correct_password_and_soft_deletes(): void
    {
        $user = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);

        $this->actingAs($user, 'web')
            ->delete(route('profile.deactivate'), ['password' => 'WrongPass1!'])
            ->assertSessionHasErrors('password');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertFalse($user->fresh()->trashed());

        $this->actingAs($user, 'web')
            ->delete(route('profile.deactivate'), ['password' => 'CorrectPass1!'])
            ->assertRedirect(route('login'));

        $this->assertGuest('web');
        $this->assertTrue($user->fresh()->trashed());
    }

    public function test_push_subscriptions_page_lists_and_can_revoke_a_device(): void
    {
        $user = $this->activeUser();
        $subscription = PushSubscription::create([
            'user_id' => $user->id,
            'fcm_token' => 'fcm-token-settings-page',
            'user_agent' => 'Mozilla/5.0 (Test Device)',
        ]);

        $this->actingAs($user, 'web')
            ->get(route('profile.push-subscriptions'))
            ->assertStatus(200)
            ->assertSee('Mozilla/5.0 (Test Device)');

        $this->actingAs($user, 'web')
            ->delete(route('profile.push-subscriptions.revoke', $subscription->uuid))
            ->assertRedirect();

        $this->assertDatabaseMissing('push_subscriptions', ['id' => $subscription->id]);
    }

    public function test_a_user_cannot_revoke_another_users_push_subscription(): void
    {
        $owner = $this->activeUser();
        $intruder = $this->activeUser();
        $subscription = PushSubscription::create([
            'user_id' => $owner->id,
            'fcm_token' => 'fcm-token-not-yours',
        ]);

        $this->actingAs($intruder, 'web')
            ->delete(route('profile.push-subscriptions.revoke', $subscription->uuid))
            ->assertStatus(404);

        $this->assertDatabaseHas('push_subscriptions', ['id' => $subscription->id]);
    }

    public function test_turning_off_every_notification_toggle_unsubscribes_every_device_but_one_toggle_off_alone_does_not(): void
    {
        $user = $this->activeUser();
        PushSubscription::create(['user_id' => $user->id, 'fcm_token' => 'fcm-token-a']);
        PushSubscription::create(['user_id' => $user->id, 'fcm_token' => 'fcm-token-b']);

        // Turning off just one toggle must not tear down subscriptions
        // still wanted for the others.
        $this->actingAs($user, 'web')->patch(route('profile.preferences'), [
            'notify_messages' => '0',
            'notify_calls' => '1',
            'notify_meetings' => '1',
            'notify_contact_requests' => '1',
        ]);
        $this->assertDatabaseCount('push_subscriptions', 2);

        // Turning off every toggle at once unsubscribes everything.
        $this->actingAs($user, 'web')->patch(route('profile.preferences'), [
            'notify_messages' => '0',
            'notify_calls' => '0',
            'notify_meetings' => '0',
            'notify_contact_requests' => '0',
        ]);
        $this->assertDatabaseMissing('push_subscriptions', ['user_id' => $user->id]);
    }
}
