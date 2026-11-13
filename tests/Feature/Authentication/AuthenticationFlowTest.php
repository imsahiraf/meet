<?php

namespace Tests\Feature\Authentication;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Modules\Authentication\Models\EmailOtp;
use Modules\Authentication\Models\PasswordResetToken;
use Modules\Authentication\Models\User;
use Modules\Authentication\Notifications\PasswordResetNotification;
use Modules\Authentication\Notifications\RegistrationOtpNotification;
use Tests\TestCase;

class AuthenticationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed default roles/permissions (Admin module) so registration
        // can auto-assign the "User" role, as it does in production.
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\DatabaseSeeder']);
    }

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '+919999999999',
            'password' => 'Str0ng!Pass',
            'password_confirmation' => 'Str0ng!Pass',
        ], $overrides);
    }

    public function test_registration_creates_pending_user_and_sends_otp_email(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', $this->registerPayload());

        $response->assertStatus(201)->assertJson(['success' => true]);

        $this->assertDatabaseHas('users', [
            'email' => 'jane@example.com',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('email_otps', [
            'email' => 'jane@example.com',
            'purpose' => 'registration',
            'status' => 'pending',
        ]);

        Notification::assertSentTo(User::where('email', 'jane@example.com')->firstOrFail(), RegistrationOtpNotification::class);
    }

    /**
     * Real bug fix: registration only ever collects a single full_name
     * field, but the Settings page has separate first_name/last_name
     * inputs - without splitting it at registration time, every real
     * user's Settings page showed those boxes blank right after
     * signup, looking exactly like nothing had saved.
     */
    public function test_registration_splits_full_name_into_profile_first_and_last_name(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', $this->registerPayload(['full_name' => 'Jane Doe']))->assertStatus(201);

        $user = User::where('email', 'jane@example.com')->firstOrFail();
        $this->assertSame('Jane', $user->profile->first_name);
        $this->assertSame('Doe', $user->profile->last_name);
    }

    public function test_registration_with_a_single_word_name_leaves_last_name_null(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', $this->registerPayload(['full_name' => 'Cher', 'email' => 'cher@example.com']))->assertStatus(201);

        $user = User::where('email', 'cher@example.com')->firstOrFail();
        $this->assertSame('Cher', $user->profile->first_name);
        $this->assertNull($user->profile->last_name);
    }

    public function test_resend_otp_does_not_fail_on_already_created_default_records(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(201);

        // UserRegistered is redispatched by resendOtp(), which also
        // triggers CreateDefaultUserRecords a second time for the same
        // user - this must not throw a duplicate-key error.
        (new \Modules\Authentication\Listeners\CreateDefaultUserRecords)->handle(
            new \Modules\Authentication\Events\UserRegistered(
                User::where('email', 'jane@example.com')->firstOrFail(),
                '123456',
            )
        );

        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/resend-otp', ['email' => 'jane@example.com'])->assertStatus(200);

        $this->assertDatabaseCount('user_profiles', 1);
        $this->assertDatabaseCount('user_preferences', 1);
        $this->assertDatabaseCount('user_privacy', 1);
        $this->assertDatabaseCount('user_presence', 1);
    }

    public function test_registration_rejects_duplicate_email_or_phone(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(201);

        $this->postJson('/api/v1/auth/register', $this->registerPayload(['phone' => '+918888888888']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/v1/auth/register', $this->registerPayload(['email' => 'other@example.com']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_login_is_rejected_before_email_verification(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', $this->registerPayload());

        $this->postJson('/api/v1/auth/login', [
            'login' => 'jane@example.com',
            'password' => 'Str0ng!Pass',
        ])->assertStatus(403);
    }

    public function test_full_registration_verification_login_and_me_flow(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(201);

        $otp = EmailOtp::where('email', 'jane@example.com')->where('purpose', 'registration')->first();
        $this->assertNotNull($otp);

        // Wrong OTP is rejected.
        $this->postJson('/api/v1/auth/verify-email', [
            'email' => 'jane@example.com',
            'otp' => '000000',
        ])->assertStatus(422);

        // Correct OTP verifies and activates the account.
        $this->postJson('/api/v1/auth/verify-email', [
            'email' => 'jane@example.com',
            'otp' => $otp->otp,
        ])->assertStatus(200)->assertJson(['success' => true]);

        $this->assertDatabaseHas('users', [
            'email' => 'jane@example.com',
            'status' => 'active',
        ]);

        // Login by email.
        $login = $this->postJson('/api/v1/auth/login', [
            'login' => 'jane@example.com',
            'password' => 'Str0ng!Pass',
        ]);
        $login->assertStatus(200)->assertJsonPath('data.user.status', 'active');
        $token = $login->json('data.token');
        $this->assertNotEmpty($token);

        // Login by phone also works.
        $this->postJson('/api/v1/auth/login', [
            'login' => '+919999999999',
            'password' => 'Str0ng!Pass',
        ])->assertStatus(200);

        // Authenticated /me reflects the auto-assigned "User" role.
        $me = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/auth/me');
        $me->assertStatus(200)->assertJsonPath('data.email', 'jane@example.com');
        $this->assertContains('User', $me->json('data.roles'));

        // Wrong password is rejected and recorded as a failed attempt.
        $this->postJson('/api/v1/auth/login', [
            'login' => 'jane@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401);

        $this->assertDatabaseHas('login_attempts', [
            'email' => 'jane@example.com',
            'login_result' => 'failed',
        ]);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('LogoutPass1!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'LogoutPass1!',
        ]);
        $token = $login->json('data.token');
        $tokenId = (int) explode('|', $token)[0];

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertStatus(200);

        // The token row and its session are gone. A second simulated
        // request in the same test process would still authenticate
        // because Sanctum's guard caches the resolved user for the
        // lifetime of the test container - not the case for real,
        // separate HTTP requests (verified manually against the dev
        // server) - so we assert persisted state directly instead.
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        $this->assertDatabaseHas('user_sessions', [
            'user_id' => $user->id,
            'session_id' => (string) $tokenId,
            'status' => 'logged_out',
        ]);
    }

    public function test_forgot_and_reset_password_flow(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'reset@example.com',
            'password' => bcrypt('OldPass1!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'reset@example.com'])
            ->assertStatus(200);

        Notification::assertSentTo($user, PasswordResetNotification::class);

        $resetToken = PasswordResetToken::where('user_id', $user->id)->where('status', 'active')->first();
        $this->assertNotNull($resetToken);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $resetToken->token,
            'password' => 'NewPass1!',
            'password_confirmation' => 'NewPass1!',
        ])->assertStatus(200);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'reset@example.com',
            'password' => 'NewPass1!',
        ])->assertStatus(200);

        // The old reset token cannot be reused.
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $resetToken->token,
            'password' => 'AnotherPass1!',
            'password_confirmation' => 'AnotherPass1!',
        ])->assertStatus(422);
    }

    public function test_change_password_requires_correct_current_password(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('CurrentPass1!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'WrongPass1!',
                'new_password' => 'BrandNewPass1!',
                'new_password_confirmation' => 'BrandNewPass1!',
            ])->assertStatus(401);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'CurrentPass1!',
                'new_password' => 'BrandNewPass1!',
                'new_password_confirmation' => 'BrandNewPass1!',
            ])->assertStatus(200);

        $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'BrandNewPass1!',
        ])->assertStatus(200);
    }
}
