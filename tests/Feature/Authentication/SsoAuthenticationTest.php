<?php

namespace Tests\Feature\Authentication;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Modules\Authentication\Models\User;
use Modules\Authentication\Models\UserOauthIdentity;
use Modules\Authentication\Services\TwoFactorService;
use Tests\TestCase;

class SsoAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGoogleUser(array $overrides = []): SocialiteUser
    {
        return SocialiteUser::fake(array_merge([
            'id' => 'google-user-1',
            'email' => 'sso.newcomer@example.test',
            'name' => 'Sso Newcomer',
        ], $overrides));
    }

    public function test_a_new_google_account_creates_a_user_with_no_password_or_phone_and_logs_them_in(): void
    {
        Socialite::fake('google', $this->fakeGoogleUser());

        $response = $this->get('/auth/google/callback');
        $response->assertRedirect(route('chat.index'));

        $user = User::where('email', 'sso.newcomer@example.test')->first();
        $this->assertNotNull($user);
        $this->assertSame('active', $user->status);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->password);
        $this->assertNull($user->phone);
        $this->assertFalse($user->hasUsablePassword());
        $this->assertAuthenticatedAs($user, 'web');

        $this->assertDatabaseHas('user_oauth_identities', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-user-1',
        ]);

        // Default records every other registration path gets too.
        $this->assertNotNull($user->fresh()->profile);
        $this->assertNotNull($user->fresh()->preferences);
    }

    public function test_signing_in_with_google_auto_links_an_existing_account_by_matching_email(): void
    {
        $user = User::factory()->create([
            'email' => 'already.here@example.test',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        Socialite::fake('google', $this->fakeGoogleUser(['id' => 'google-user-2', 'email' => 'already.here@example.test']));

        $this->get('/auth/google/callback')->assertRedirect(route('chat.index'));

        $this->assertAuthenticatedAs($user->fresh(), 'web');
        $this->assertDatabaseHas('user_oauth_identities', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-user-2',
        ]);
    }

    public function test_a_second_login_with_an_already_linked_identity_signs_in_without_creating_a_duplicate(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        UserOauthIdentity::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-user-3',
        ]);

        Socialite::fake('google', $this->fakeGoogleUser(['id' => 'google-user-3', 'email' => 'a-totally-different-email@example.test']));

        $this->get('/auth/google/callback')->assertRedirect(route('chat.index'));

        $this->assertAuthenticatedAs($user->fresh(), 'web');
        $this->assertSame(1, UserOauthIdentity::where('provider_user_id', 'google-user-3')->count());
    }

    public function test_a_suspended_users_google_login_is_blocked(): void
    {
        User::factory()->create([
            'email' => 'suspended@example.test',
            'status' => 'suspended',
            'email_verified_at' => now(),
        ]);

        Socialite::fake('google', $this->fakeGoogleUser(['id' => 'google-user-4', 'email' => 'suspended@example.test']));

        $this->get('/auth/google/callback')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_google_login_with_two_factor_enabled_still_stops_at_the_challenge(): void
    {
        $user = User::factory()->create([
            'email' => 'has2fa@example.test',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        app(TwoFactorService::class)->startSetup($user);
        $secret = \Modules\Authentication\Models\TwoFactorSecret::where('user_id', $user->id)->first();
        app(TwoFactorService::class)->confirm($user, app(\PragmaRX\Google2FAQRCode\Google2FA::class)->getCurrentOtp($secret->secret));

        Socialite::fake('google', $this->fakeGoogleUser(['id' => 'google-user-5', 'email' => 'has2fa@example.test']));

        $response = $this->get('/auth/google/callback');
        $response->assertRedirect();
        $this->assertStringContainsString('two-factor-challenge', $response->headers->get('Location'));
        $this->assertGuest('web');
    }

    public function test_unlinking_the_only_login_method_of_a_passwordless_account_is_refused(): void
    {
        Socialite::fake('google', $this->fakeGoogleUser());
        $this->get('/auth/google/callback');

        $user = User::where('email', 'sso.newcomer@example.test')->first();
        $identity = UserOauthIdentity::where('user_id', $user->id)->first();

        $this->actingAs($user, 'web')
            ->delete("/profile/oauth/{$identity->id}")
            ->assertSessionHasErrors('oauth');

        $this->assertDatabaseHas('user_oauth_identities', ['id' => $identity->id]);
    }

    public function test_unlinking_is_allowed_when_the_user_has_a_real_password(): void
    {
        $user = User::factory()->create([
            'email' => 'has.password@example.test',
            'password' => bcrypt('CorrectPass1!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $identity = UserOauthIdentity::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-user-6',
        ]);

        $this->actingAs($user, 'web')
            ->delete("/profile/oauth/{$identity->id}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('user_oauth_identities', ['id' => $identity->id]);
    }

    public function test_a_user_cannot_unlink_another_users_identity(): void
    {
        $owner = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $intruder = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $identity = UserOauthIdentity::create([
            'user_id' => $owner->id,
            'provider' => 'google',
            'provider_user_id' => 'google-user-7',
        ]);

        $this->actingAs($intruder, 'web')
            ->delete("/profile/oauth/{$identity->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('user_oauth_identities', ['id' => $identity->id]);
    }

    /**
     * Previously an uncaught 500 - a genuinely reachable state
     * (an expired/replaced session, a stale tab reusing an old
     * callback link, or a session cookie the browser silently refused
     * to overwrite), not just a theoretical one, so it needs a real
     * recovery path rather than a crash page.
     */
    public function test_a_state_mismatch_on_the_google_callback_redirects_to_login_with_a_friendly_message_instead_of_crashing(): void
    {
        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturnUsing(function () {
                $provider = Mockery::mock(Provider::class);
                $provider->shouldReceive('user')->andThrow(new InvalidStateException());

                return $provider;
            });

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors(['login']);
        $this->assertGuest('web');
    }
}
