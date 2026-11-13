<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Authentication\Exceptions\AccountNotVerifiedException;
use Modules\Authentication\Exceptions\AccountSuspendedException;
use Modules\Authentication\Exceptions\InvalidCredentialsException;
use Modules\Authentication\Http\Requests\LoginRequest;
use Modules\Authentication\Repositories\UserSessionRepository;
use Modules\Authentication\Services\AccountSwitcherService;
use Modules\Authentication\Services\AuthenticationService;

/**
 * "Switch account without re-entering your password" - reachable
 * whether the browser is currently a guest (the login page's "Continue
 * as..." tiles) or already authenticated as someone else (an in-app
 * "Switch account" action, no logout round-trip needed first). Not
 * gated behind 'auth' or 'guest' for that reason - ownership is proved
 * by AccountSwitcherService::resolve()'s remember_token check, not by
 * whatever's currently logged in.
 */
class AccountSwitcherController extends Controller
{
    public function __construct(
        private readonly AccountSwitcherService $accountSwitcher,
        private readonly UserSessionRepository $sessions,
        private readonly AuthenticationService $auth,
    ) {
    }

    public function switch(Request $request, int $userId): RedirectResponse
    {
        $user = $this->accountSwitcher->resolve($request, $userId);

        if (! $user || $user->status !== 'active') {
            $this->accountSwitcher->forget($request, $userId);

            return redirect()->route('login')->withErrors(['login' => 'That account is no longer available on this device - please log in again.']);
        }

        Auth::guard('web')->loginUsingId($user->id, remember: true);
        $request->session()->regenerate();
        $this->sessions->createFromRequest($user, $request, $request->session()->getId());

        // The token above is confirmed still valid - re-save it under
        // this account's entry so the cookie's expiry keeps sliding
        // forward each time it's actually used.
        $this->accountSwitcher->remember($request, $user);

        return redirect()->route('chat.index');
    }

    public function forget(Request $request, int $userId): RedirectResponse
    {
        // Same real logout path a normal /logout uses (terminates the
        // session row, marks presence offline, etc.) when the account
        // being forgotten is the one currently live - logoutWeb() itself
        // also forgets it from the switcher, so no separate call needed
        // in that branch.
        if ($request->user()?->id === $userId) {
            $this->auth->logoutWeb($request);

            return redirect()->route('login');
        }

        $this->accountSwitcher->forget($request, $userId);

        return back();
    }

    /**
     * "Add another account" - a real login form, but reachable while
     * already authenticated as someone else, unlike the normal 'guest'-
     * gated /login. Establishing the new session here never touches the
     * outgoing account's own session or remember_token (Laravel's
     * SessionGuard::login() just swaps which user the guard holds), so
     * it stays fully intact and switchable back to afterward.
     */
    public function showAdd(): View
    {
        return view('authentication::add-account');
    }

    public function add(LoginRequest $request): RedirectResponse
    {
        try {
            $result = $this->auth->loginForWeb($request, $request->string('login'), $request->string('password'));
        } catch (AccountNotVerifiedException) {
            return redirect()->route('verify-email.show', ['email' => $request->input('login')])
                ->withErrors(['login' => 'Please verify your email before logging in.']);
        } catch (InvalidCredentialsException|AccountSuspendedException $e) {
            return back()->withErrors(['login' => $e->getMessage()])->onlyInput('login');
        }

        if ($result['requires_2fa']) {
            return redirect()->route('two-factor.challenge.show', ['challenge' => $result['challenge_token']]);
        }

        return redirect()->route('chat.index');
    }
}
