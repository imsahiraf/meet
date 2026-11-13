<?php

namespace Tests\Feature\Authentication;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\TrustedDevice;
use Modules\Authentication\Models\TwoFactorChallenge;
use Modules\Authentication\Models\TwoFactorSecret;
use Modules\Authentication\Models\User;
use Modules\Authentication\Services\TwoFactorService;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }

    private function currentCodeFor(User $user): string
    {
        $secret = TwoFactorSecret::where('user_id', $user->id)->first();

        return app(Google2FA::class)->getCurrentOtp($secret->secret);
    }

    /**
     * Enables 2FA via the real HTTP endpoints (as the given user would),
     * then logs the test client back out - actingAs() leaves the test's
     * HTTP client authenticated for subsequent requests, and a
     * subsequent /login attempt needs to start from a genuine guest
     * state or the "guest" middleware bounces it straight to chat.index
     * before the login logic (and any 2FA check) ever runs.
     */
    private function enableTwoFactorFor(User $user): array
    {
        $this->actingAs($user, 'web')->post('/profile/two-factor');
        $code = $this->currentCodeFor($user);
        $this->actingAs($user, 'web')->post('/profile/two-factor/confirm', ['code' => $code]);

        $recoveryCodes = TwoFactorSecret::where('user_id', $user->id)->first()->recovery_codes;

        $this->post('/logout');

        return $recoveryCodes;
    }

    public function test_enabling_two_factor_requires_a_real_confirmation_code_before_becoming_active(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user, 'web')->post('/profile/two-factor')->assertRedirect();
        $secret = TwoFactorSecret::where('user_id', $user->id)->first();
        $this->assertNotNull($secret);
        $this->assertNull($secret->confirmed_at, 'expected 2FA to not be active until a real code confirms it');

        $this->actingAs($user, 'web')
            ->post('/profile/two-factor/confirm', ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertNull($secret->fresh()->confirmed_at);

        // enableTwoFactorFor() below restarts setup (deleting the
        // unconfirmed row above and creating a fresh one - re-enabling
        // always starts clean), so the confirmed state must be checked
        // against a fresh lookup, not the now-deleted $secret row.
        $recoveryCodes = $this->enableTwoFactorFor($user);
        $this->assertNotNull(TwoFactorSecret::where('user_id', $user->id)->first()->confirmed_at);
        $this->assertCount(8, $recoveryCodes);
    }

    public function test_web_login_with_two_factor_enabled_requires_a_code_before_establishing_a_session(): void
    {
        $user = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);
        $this->enableTwoFactorFor($user);

        $response = $this->post('/login', ['login' => $user->email, 'password' => 'CorrectPass1!']);
        $response->assertRedirect();
        $this->assertStringContainsString('two-factor-challenge', $response->headers->get('Location'));
        $this->assertGuest('web');

        $challengeToken = TwoFactorChallenge::where('user_id', $user->id)->orderByDesc('id')->first()->uuid;
        $this->assertNotNull($challengeToken);

        $code = $this->currentCodeFor($user);
        $this->post('/two-factor-challenge', ['challenge_token' => $challengeToken, 'code' => $code])
            ->assertRedirect(route('chat.index'));

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_web_login_with_two_factor_rejects_the_wrong_code(): void
    {
        $user = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);
        $this->enableTwoFactorFor($user);

        $this->post('/login', ['login' => $user->email, 'password' => 'CorrectPass1!']);
        $challengeToken = TwoFactorChallenge::where('user_id', $user->id)->orderByDesc('id')->first()->uuid;

        $this->post('/two-factor-challenge', ['challenge_token' => $challengeToken, 'code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertGuest('web');
    }

    public function test_a_recovery_code_works_once_then_is_rejected_the_second_time(): void
    {
        $user = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);
        $recoveryCodes = $this->enableTwoFactorFor($user);
        $recoveryCode = $recoveryCodes[0];

        $this->post('/login', ['login' => $user->email, 'password' => 'CorrectPass1!']);
        $challengeToken = TwoFactorChallenge::where('user_id', $user->id)->orderByDesc('id')->first()->uuid;

        $this->post('/two-factor-challenge', ['challenge_token' => $challengeToken, 'code' => $recoveryCode])
            ->assertRedirect(route('chat.index'));
        $this->assertAuthenticatedAs($user, 'web');

        $this->post('/logout');

        $this->post('/login', ['login' => $user->email, 'password' => 'CorrectPass1!']);
        $secondChallengeToken = TwoFactorChallenge::where('user_id', $user->id)->orderByDesc('id')->first()->uuid;

        $this->post('/two-factor-challenge', ['challenge_token' => $secondChallengeToken, 'code' => $recoveryCode])
            ->assertSessionHasErrors('code');
        $this->assertGuest('web');
    }

    public function test_api_login_with_two_factor_returns_a_challenge_token_instead_of_a_token_until_verified(): void
    {
        $user = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);
        $this->enableTwoFactorFor($user);

        $response = $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'CorrectPass1!']);
        $response->assertStatus(200)->assertJsonPath('data.requires_2fa', true);
        $challengeToken = $response->json('data.challenge_token');
        $this->assertNotEmpty($challengeToken);
        $this->assertArrayNotHasKey('token', $response->json('data'));

        $code = $this->currentCodeFor($user);
        $verify = $this->postJson('/api/v1/auth/two-factor/verify', ['challenge_token' => $challengeToken, 'code' => $code]);
        $verify->assertStatus(200)->assertJsonPath('data.requires_2fa', false);
        $this->assertNotEmpty($verify->json('data.token'));
    }

    public function test_disabling_two_factor_requires_the_current_password(): void
    {
        $user = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);
        $this->enableTwoFactorFor($user);

        $this->actingAs($user, 'web')
            ->delete('/profile/two-factor', ['password' => 'WrongPass1!'])
            ->assertSessionHasErrors('password');
        $this->assertTrue(app(TwoFactorService::class)->isEnabledFor($user->fresh()));

        $this->actingAs($user, 'web')
            ->delete('/profile/two-factor', ['password' => 'CorrectPass1!'])
            ->assertSessionHasNoErrors();
        $this->assertFalse(app(TwoFactorService::class)->isEnabledFor($user->fresh()));
    }

    public function test_a_non_2fa_user_still_logs_in_normally_with_no_challenge(): void
    {
        $user = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);

        $this->post('/login', ['login' => $user->email, 'password' => 'CorrectPass1!'])
            ->assertRedirect(route('chat.index'));
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_checking_remember_this_device_skips_the_challenge_on_the_next_login(): void
    {
        $user = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);
        $this->enableTwoFactorFor($user);

        $this->post('/login', ['login' => $user->email, 'password' => 'CorrectPass1!']);
        $challengeToken = TwoFactorChallenge::where('user_id', $user->id)->orderByDesc('id')->first()->uuid;

        $verifyResponse = $this->post('/two-factor-challenge', [
            'challenge_token' => $challengeToken,
            'code' => $this->currentCodeFor($user),
            'remember_device' => '1',
        ]);
        $verifyResponse->assertRedirect(route('chat.index'));

        $cookie = $verifyResponse->getCookie(TwoFactorService::TRUSTED_DEVICE_COOKIE);
        $this->assertNotNull($cookie, 'expected a trusted-device cookie to be set when "remember this device" is checked');
        $this->assertDatabaseCount('trusted_devices', 1);

        $this->post('/logout');

        // Presenting that same cookie on the next login skips the
        // challenge entirely - straight to an authenticated session.
        $this->withCookie(TwoFactorService::TRUSTED_DEVICE_COOKIE, $cookie->getValue())
            ->post('/login', ['login' => $user->email, 'password' => 'CorrectPass1!'])
            ->assertRedirect(route('chat.index'));

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_leaving_remember_this_device_unchecked_still_challenges_on_the_next_login(): void
    {
        $user = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);
        $this->enableTwoFactorFor($user);

        $this->post('/login', ['login' => $user->email, 'password' => 'CorrectPass1!']);
        $challengeToken = TwoFactorChallenge::where('user_id', $user->id)->orderByDesc('id')->first()->uuid;

        $verifyResponse = $this->post('/two-factor-challenge', [
            'challenge_token' => $challengeToken,
            'code' => $this->currentCodeFor($user),
        ]);
        $verifyResponse->assertRedirect(route('chat.index'));

        $this->assertNull($verifyResponse->getCookie(TwoFactorService::TRUSTED_DEVICE_COOKIE), 'expected no trusted-device cookie without opting in');
        $this->assertDatabaseCount('trusted_devices', 0);

        $this->post('/logout');

        $response = $this->post('/login', ['login' => $user->email, 'password' => 'CorrectPass1!']);
        $this->assertStringContainsString('two-factor-challenge', $response->headers->get('Location'));
        $this->assertGuest('web');
    }

    public function test_revoking_a_trusted_device_forces_a_challenge_again(): void
    {
        $user = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);
        $this->enableTwoFactorFor($user);

        $this->post('/login', ['login' => $user->email, 'password' => 'CorrectPass1!']);
        $challengeToken = TwoFactorChallenge::where('user_id', $user->id)->orderByDesc('id')->first()->uuid;
        $verifyResponse = $this->post('/two-factor-challenge', [
            'challenge_token' => $challengeToken,
            'code' => $this->currentCodeFor($user),
            'remember_device' => '1',
        ]);
        $cookieValue = $verifyResponse->getCookie(TwoFactorService::TRUSTED_DEVICE_COOKIE)->getValue();
        $this->post('/logout');

        // Revoke it from Settings, as the user would. actingAs() leaves
        // the test client authenticated for subsequent requests, so a
        // fresh /logout is needed before the next /login attempt can
        // reach the guest-only login logic at all (same pitfall
        // enableTwoFactorFor() above already works around).
        $device = TrustedDevice::where('user_id', $user->id)->first();
        $this->actingAs($user, 'web')->delete("/profile/trusted-devices/{$device->id}")->assertRedirect();
        $this->post('/logout');

        // The same cookie no longer works - a challenge is required again.
        $response = $this->withCookie(TwoFactorService::TRUSTED_DEVICE_COOKIE, $cookieValue)
            ->post('/login', ['login' => $user->email, 'password' => 'CorrectPass1!']);
        $this->assertStringContainsString('two-factor-challenge', $response->headers->get('Location'));
        $this->assertGuest('web');
    }

    public function test_an_expired_trusted_device_still_requires_a_challenge(): void
    {
        $user = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);
        $this->enableTwoFactorFor($user);

        $this->post('/login', ['login' => $user->email, 'password' => 'CorrectPass1!']);
        $challengeToken = TwoFactorChallenge::where('user_id', $user->id)->orderByDesc('id')->first()->uuid;
        $verifyResponse = $this->post('/two-factor-challenge', [
            'challenge_token' => $challengeToken,
            'code' => $this->currentCodeFor($user),
            'remember_device' => '1',
        ]);
        $cookieValue = $verifyResponse->getCookie(TwoFactorService::TRUSTED_DEVICE_COOKIE)->getValue();
        $this->post('/logout');

        TrustedDevice::where('user_id', $user->id)->update(['expires_at' => now()->subDay()]);

        $response = $this->withCookie(TwoFactorService::TRUSTED_DEVICE_COOKIE, $cookieValue)
            ->post('/login', ['login' => $user->email, 'password' => 'CorrectPass1!']);
        $this->assertStringContainsString('two-factor-challenge', $response->headers->get('Location'));
        $this->assertGuest('web');
    }

    public function test_trusted_devices_settings_page_lists_and_can_forget_a_device(): void
    {
        $user = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);
        $this->enableTwoFactorFor($user);

        $this->post('/login', ['login' => $user->email, 'password' => 'CorrectPass1!']);
        $challengeToken = TwoFactorChallenge::where('user_id', $user->id)->orderByDesc('id')->first()->uuid;
        $this->post('/two-factor-challenge', [
            'challenge_token' => $challengeToken,
            'code' => $this->currentCodeFor($user),
            'remember_device' => '1',
        ]);

        $device = TrustedDevice::where('user_id', $user->id)->firstOrFail();

        $this->actingAs($user, 'web')
            ->get('/profile/trusted-devices')
            ->assertStatus(200)
            ->assertSee('Forget');

        $this->actingAs($user, 'web')
            ->delete("/profile/trusted-devices/{$device->id}")
            ->assertRedirect();

        $this->assertFalse($device->fresh()->isActive());
    }

    public function test_a_user_cannot_revoke_another_users_trusted_device(): void
    {
        $owner = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);
        $intruder = $this->activeUser();
        $this->enableTwoFactorFor($owner);

        $this->post('/login', ['login' => $owner->email, 'password' => 'CorrectPass1!']);
        $challengeToken = TwoFactorChallenge::where('user_id', $owner->id)->orderByDesc('id')->first()->uuid;
        $this->post('/two-factor-challenge', [
            'challenge_token' => $challengeToken,
            'code' => $this->currentCodeFor($owner),
            'remember_device' => '1',
        ]);
        $this->post('/logout');

        $device = TrustedDevice::where('user_id', $owner->id)->firstOrFail();

        $this->actingAs($intruder, 'web')
            ->delete("/profile/trusted-devices/{$device->id}")
            ->assertStatus(403);

        $this->assertTrue($device->fresh()->isActive());
    }
}
