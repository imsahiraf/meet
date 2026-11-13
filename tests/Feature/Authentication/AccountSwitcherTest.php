<?php

namespace Tests\Feature\Authentication;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Authentication\Services\AccountSwitcherService;
use Tests\TestCase;

class AccountSwitcherTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }

    public function test_logging_in_with_remember_checked_adds_the_account_to_the_switcher_cookie(): void
    {
        $user = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);

        $response = $this->post('/login', ['login' => $user->email, 'password' => 'CorrectPass1!', 'remember' => '1']);
        $response->assertRedirect(route('chat.index'));

        $cookie = $response->getCookie(AccountSwitcherService::COOKIE);
        $this->assertNotNull($cookie, 'expected the switcher cookie to be set');
        $accounts = json_decode($cookie->getValue(), true);
        $this->assertCount(1, $accounts);
        $this->assertSame($user->id, $accounts[0]['user_id']);
        $this->assertArrayNotHasKey('password', $accounts[0]);
    }

    public function test_logging_in_with_remember_unchecked_does_not_add_to_the_switcher_cookie(): void
    {
        $user = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);

        $response = $this->post('/login', ['login' => $user->email, 'password' => 'CorrectPass1!', 'remember' => '0']);
        $response->assertRedirect(route('chat.index'));

        $this->assertNull($response->getCookie(AccountSwitcherService::COOKIE));
    }

    /**
     * The real point of a switcher: adding a second account via the
     * dedicated /accounts/add flow (not the normal 'guest'-gated
     * /login) leaves the first account's own session/remember_token
     * completely untouched, so switching straight back to it needs no
     * password - a one-step jump between two live accounts, not a
     * logout-then-log-back-in round trip.
     */
    public function test_adding_a_second_account_then_switching_back_needs_no_password(): void
    {
        $alice = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);
        $bob = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);

        $aliceLogin = $this->post('/login', ['login' => $alice->email, 'password' => 'CorrectPass1!', 'remember' => '1']);
        $aliceCookie = $aliceLogin->getCookie(AccountSwitcherService::COOKIE)->getValue();
        $this->assertAuthenticatedAs($alice, 'web');

        $bobLogin = $this->withCookie(AccountSwitcherService::COOKIE, $aliceCookie)
            ->post('/accounts/add', ['login' => $bob->email, 'password' => 'CorrectPass1!', 'remember' => '1']);
        $bobLogin->assertRedirect(route('chat.index'));
        $this->assertAuthenticatedAs($bob, 'web');

        $bothCookie = $bobLogin->getCookie(AccountSwitcherService::COOKIE)->getValue();
        $bothAccounts = json_decode($bothCookie, true);
        $this->assertCount(2, $bothAccounts, 'expected both alice and bob held after adding bob');

        // Currently authenticated as bob - switch directly to alice, no
        // logout step, no password.
        $this->withCookie(AccountSwitcherService::COOKIE, $bothCookie)
            ->post("/accounts/{$alice->id}/switch")
            ->assertRedirect(route('chat.index'));

        $this->assertAuthenticatedAs($alice, 'web');
    }

    /**
     * Laravel's own Auth::guard('web')->logout() deliberately rotates
     * remember_token on an explicit logout (invalidating any "remember
     * me" cookie) - logoutWeb() also drops the switcher entry to match,
     * so a genuinely-logged-out account can't be silently resumed
     * without the password again.
     */
    public function test_explicitly_logging_out_forgets_the_account_from_the_switcher(): void
    {
        $user = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);

        $loginResponse = $this->post('/login', ['login' => $user->email, 'password' => 'CorrectPass1!', 'remember' => '1']);
        $cookieValue = $loginResponse->getCookie(AccountSwitcherService::COOKIE)->getValue();

        $logoutResponse = $this->withCookie(AccountSwitcherService::COOKIE, $cookieValue)->post('/logout');
        $afterLogoutCookie = $logoutResponse->getCookie(AccountSwitcherService::COOKIE);
        $this->assertNotNull($afterLogoutCookie);
        $this->assertSame([], json_decode($afterLogoutCookie->getValue(), true));

        $switchAttempt = $this->withCookie(AccountSwitcherService::COOKIE, $cookieValue)->post("/accounts/{$user->id}/switch");
        $switchAttempt->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_switching_with_a_stale_remember_token_fails_and_forgets_the_entry(): void
    {
        // Deliberately never logs in via a real /login call here - the
        // test client stays authenticated across calls within one test
        // method, which would make a later assertGuest() pass or fail
        // for the wrong reason regardless of what /accounts/switch
        // itself did (the same pitfall already documented elsewhere in
        // this suite). Constructing the held cookie directly instead
        // keeps this test isolated to exactly what it's checking: a
        // stale token in the cookie is rejected, not "does /login work".
        $user = $this->activeUser(['remember_token' => 'original-token']);
        $cookieValue = json_encode([
            ['user_id' => $user->id, 'uuid' => $user->uuid, 'full_name' => $user->full_name, 'remember_token' => 'original-token'],
        ]);

        // Rotate the token elsewhere (e.g. an admin force-logout, or a
        // real password change) - the held cookie's copy is now stale.
        $user->forceFill(['remember_token' => 'a-different-token'])->save();

        $response = $this->withCookie(AccountSwitcherService::COOKIE, $cookieValue)->post("/accounts/{$user->id}/switch");
        $response->assertRedirect(route('login'));
        $this->assertGuest('web');

        $forgetCookie = $response->getCookie(AccountSwitcherService::COOKIE);
        $this->assertSame([], json_decode($forgetCookie->getValue(), true), 'expected the stale entry to be dropped');
    }

    public function test_forgetting_a_different_account_does_not_log_out_the_currently_active_one(): void
    {
        $alice = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);
        $bob = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);

        $aliceLogin = $this->post('/login', ['login' => $alice->email, 'password' => 'CorrectPass1!', 'remember' => '1']);
        $aliceCookie = $aliceLogin->getCookie(AccountSwitcherService::COOKIE)->getValue();

        $bobLogin = $this->withCookie(AccountSwitcherService::COOKIE, $aliceCookie)
            ->post('/accounts/add', ['login' => $bob->email, 'password' => 'CorrectPass1!', 'remember' => '1']);
        $bothCookie = $bobLogin->getCookie(AccountSwitcherService::COOKIE)->getValue();

        // Bob is currently active; forget alice specifically - bob stays logged in.
        $response = $this->withCookie(AccountSwitcherService::COOKIE, $bothCookie)->post("/accounts/{$alice->id}/forget");
        $this->assertAuthenticatedAs($bob, 'web');

        $remaining = json_decode($response->getCookie(AccountSwitcherService::COOKIE)->getValue(), true);
        $this->assertCount(1, $remaining);
        $this->assertSame($bob->id, $remaining[0]['user_id']);
    }

    public function test_forgetting_the_currently_active_account_also_logs_out(): void
    {
        $user = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);

        $loginResponse = $this->post('/login', ['login' => $user->email, 'password' => 'CorrectPass1!', 'remember' => '1']);
        $cookieValue = $loginResponse->getCookie(AccountSwitcherService::COOKIE)->getValue();

        $response = $this->withCookie(AccountSwitcherService::COOKIE, $cookieValue)->post("/accounts/{$user->id}/forget");
        $response->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_login_page_shows_continue_as_tiles_for_held_accounts(): void
    {
        $alice = $this->activeUser(['password' => bcrypt('CorrectPass1!'), 'full_name' => 'Held Account Person']);
        $bob = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);

        $aliceLogin = $this->post('/login', ['login' => $alice->email, 'password' => 'CorrectPass1!', 'remember' => '1']);
        $aliceCookie = $aliceLogin->getCookie(AccountSwitcherService::COOKIE)->getValue();

        $bobLogin = $this->withCookie(AccountSwitcherService::COOKIE, $aliceCookie)
            ->post('/accounts/add', ['login' => $bob->email, 'password' => 'CorrectPass1!', 'remember' => '1']);
        $bothCookie = $bobLogin->getCookie(AccountSwitcherService::COOKIE)->getValue();

        // Log bob out explicitly (removes only bob) so the login page is
        // reachable ('guest' middleware) while alice is still held.
        $this->withCookie(AccountSwitcherService::COOKIE, $bothCookie)->post('/logout');

        $this->withCookie(AccountSwitcherService::COOKIE, $bothCookie)
            ->get('/login')
            ->assertOk()
            ->assertSee('Held Account Person')
            ->assertSee('Choose an account');
    }

    public function test_a_suspended_account_cannot_be_switched_into(): void
    {
        $alice = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);
        $bob = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);

        $aliceLogin = $this->post('/login', ['login' => $alice->email, 'password' => 'CorrectPass1!', 'remember' => '1']);
        $aliceCookie = $aliceLogin->getCookie(AccountSwitcherService::COOKIE)->getValue();
        $bobLogin = $this->withCookie(AccountSwitcherService::COOKIE, $aliceCookie)
            ->post('/accounts/add', ['login' => $bob->email, 'password' => 'CorrectPass1!', 'remember' => '1']);
        $bothCookie = $bobLogin->getCookie(AccountSwitcherService::COOKIE)->getValue();

        $alice->forceFill(['status' => 'suspended'])->save();

        $this->withCookie(AccountSwitcherService::COOKIE, $bothCookie)
            ->post("/accounts/{$alice->id}/switch")
            ->assertRedirect(route('login'));
        $this->assertAuthenticatedAs($bob, 'web');
    }
}
