<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Modules\Authentication\Models\EmailOtp;
use Modules\Authentication\Models\PasswordResetToken;
use Modules\Authentication\Models\User;
use Modules\User\Models\UserPresence;
use Tests\TestCase;

/**
 * Exercises the session-based (Blade UI) auth controllers end-to-end.
 * These use different code paths from the API tests (AuthenticationFlowTest)
 * - session login, CSRF, redirects - so they catch bugs the API tests
 * structurally cannot (e.g. the WebAuthController::verifyEmail() call
 * into a since-renamed service method that only a real request path
 * would exercise).
 */
class WebAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\DatabaseSeeder']);
    }

    public function test_full_web_registration_verification_and_dashboard_access(): void
    {
        Notification::fake();

        $this->get('/register')->assertStatus(200);

        $this->post('/register', [
            'full_name' => 'Web Tester',
            'email' => 'webtester@example.com',
            'phone' => '+911230001111',
            'password' => 'Str0ng!Pass',
            'password_confirmation' => 'Str0ng!Pass',
        ])->assertRedirect(route('verify-email.show', ['email' => 'webtester@example.com']));

        $this->assertDatabaseHas('users', ['email' => 'webtester@example.com', 'status' => 'pending']);

        $otp = EmailOtp::where('email', 'webtester@example.com')->where('purpose', 'registration')->first();

        // Wrong OTP redisplays the form with an error, not a crash.
        $this->post('/verify-email', ['email' => 'webtester@example.com', 'otp' => '000000'])
            ->assertSessionHasErrors('otp');

        // Correct OTP verifies, auto-logs-in (FR-002), and reaches Chat (the landing page).
        $this->post('/verify-email', ['email' => 'webtester@example.com', 'otp' => $otp->otp])
            ->assertRedirect(route('chat.index'));

        $this->assertAuthenticatedAs(User::where('email', 'webtester@example.com')->first(), 'web');

        $this->get('/dashboard')->assertStatus(200)->assertSee('Web Tester', escape: false);
    }

    public function test_web_login_and_logout(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('WebLoginPass1!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->post('/login', ['login' => $user->email, 'password' => 'WebLoginPass1!'])
            ->assertRedirect(route('chat.index'));

        $this->assertAuthenticatedAs($user, 'web');

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    /**
     * Nothing else in the app ever marks a user offline (presence is
     * heartbeat-only), so without this a logged-out user would keep
     * showing as online/away/busy to everyone else indefinitely.
     */
    public function test_logout_marks_the_user_offline(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('WebLoginPass1!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->presence()->create(['presence_status' => 'online']);

        $this->post('/login', ['login' => $user->email, 'password' => 'WebLoginPass1!']);
        $this->post('/logout');

        $this->assertSame('offline', UserPresence::where('user_id', $user->id)->first()->presence_status);
    }

    /**
     * Regression test for a real production bug: CheckMaintenanceMode
     * was registered as a *global* middleware (bootstrap/app.php),
     * which runs before StartSession - so request()->user() was always
     * null when it checked for an admin bypass, and every admin got a
     * 503 from every page during maintenance mode, not just regular
     * users. A test using actingAs() cannot catch this class of bug at
     * all (it force-authenticates the guard directly, bypassing the
     * real middleware pipeline entirely) - only a real session started
     * via an actual /login request, like this one, exercises the exact
     * ordering that was broken.
     */
    public function test_maintenance_mode_does_not_block_an_admin_over_a_real_session(): void
    {
        $admin = User::factory()->create([
            'password' => bcrypt('AdminPass1!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        \Modules\Admin\Models\Admin::create(['user_id' => $admin->id, 'admin_level' => 'super', 'is_super_admin' => true]);

        $this->post('/login', ['login' => $admin->email, 'password' => 'AdminPass1!'])
            ->assertRedirect(route('chat.index'));

        app(\Modules\Admin\Services\SettingsService::class)->set('maintenance_mode_enabled', '1', 'general', 'boolean');

        $this->get(route('dashboard'))->assertStatus(200);
    }

    public function test_maintenance_mode_does_not_block_an_admin_api_action_over_a_real_session(): void
    {
        Notification::fake();
        \Illuminate\Support\Facades\Mail::fake();

        $admin = User::factory()->create([
            'password' => bcrypt('AdminPass1!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        \Modules\Admin\Models\Admin::create(['user_id' => $admin->id, 'admin_level' => 'super', 'is_super_admin' => true]);

        $this->post('/login', ['login' => $admin->email, 'password' => 'AdminPass1!'])
            ->assertRedirect(route('chat.index'));

        app(\Modules\Admin\Services\SettingsService::class)->set('maintenance_mode_enabled', '1', 'general', 'boolean');

        // 503 would mean maintenance mode blocked it (the bug); any
        // other status means it reached the real controller logic.
        $this->postJson('/api/v1/admin/settings/smtp/test')->assertStatus(200);
    }

    public function test_maintenance_mode_blocks_a_regular_user_over_a_real_session(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('UserPass1!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->post('/login', ['login' => $user->email, 'password' => 'UserPass1!'])
            ->assertRedirect(route('chat.index'));

        app(\Modules\Admin\Services\SettingsService::class)->set('maintenance_mode_enabled', '1', 'general', 'boolean');

        $this->get(route('dashboard'))->assertStatus(503);
    }

    public function test_web_login_rejects_wrong_password(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('CorrectPass1!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->post('/login', ['login' => $user->email, 'password' => 'WrongPass1!'])
            ->assertSessionHasErrors('login');

        $this->assertGuest('web');
    }

    public function test_web_forgot_and_reset_password(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'webreset@example.com',
            'password' => bcrypt('OldPass1!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->post('/forgot-password', ['email' => 'webreset@example.com'])
            ->assertRedirect();

        $resetToken = PasswordResetToken::where('user_id', $user->id)->where('status', 'active')->first();
        $this->assertNotNull($resetToken);

        $this->post('/reset-password', [
            'token' => $resetToken->token,
            'password' => 'NewPass1!',
            'password_confirmation' => 'NewPass1!',
        ])->assertRedirect(route('login'));

        $this->post('/login', ['login' => 'webreset@example.com', 'password' => 'NewPass1!'])
            ->assertRedirect(route('chat.index'));
    }

    public function test_guest_middleware_redirects_authenticated_users_away_from_login(): void
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);

        $this->actingAs($user, 'web')->get('/login')->assertRedirect(route('chat.index'));
    }

    public function test_auth_middleware_redirects_guests_away_from_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }
}
