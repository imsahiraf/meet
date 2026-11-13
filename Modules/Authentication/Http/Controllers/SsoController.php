<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Modules\Authentication\Exceptions\AccountNotVerifiedException;
use Modules\Authentication\Exceptions\AccountSuspendedException;
use Modules\Authentication\Services\SsoService;

class SsoController extends Controller
{
    public function __construct(
        private readonly SsoService $sso,
    ) {
    }

    public function redirect(string $provider): RedirectResponse
    {
        abort_unless($provider === 'google', 404);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless($provider === 'google', 404);

        try {
            $socialiteUser = Socialite::driver($provider)->user();
        } catch (InvalidStateException $e) {
            // This was previously an uncaught 500 - the user's own
            // session simply didn't have the `state` Google handed
            // back (an expired/replaced session, a stale tab reusing
            // an old callback link, or a session cookie the browser
            // silently refused to overwrite - e.g. a Secure-flagged
            // cookie from a past https/tunnel visit to this same host
            // blocking a plain http Set-Cookie from ever landing).
            // Logged with enough context to tell those apart if it
            // keeps happening, rather than guessing again from scratch.
            Log::warning('Google sign-in state mismatch', [
                'has_session_cookie' => $request->hasCookie(config('session.cookie')),
                'session_id' => $request->hasSession() ? $request->session()->getId() : null,
                'query_state_present' => $request->query('state') !== null,
            ]);

            return redirect()->route('login')->withErrors([
                'login' => 'That Google sign-in link expired or was already used - please try "Continue with Google" again.',
            ]);
        }

        if (! $socialiteUser->getEmail()) {
            return redirect()->route('login')->withErrors(['login' => 'Your Google account has no email address we can sign in with.']);
        }

        try {
            $result = $this->sso->loginOrRegister($request, $provider, $socialiteUser);
        } catch (AccountSuspendedException|AccountNotVerifiedException $e) {
            return redirect()->route('login')->withErrors(['login' => $e->getMessage()]);
        }

        if ($result['requires_2fa']) {
            return redirect()->route('two-factor.challenge.show', ['challenge' => $result['challenge_token']]);
        }

        return redirect()->intended(route('chat.index'));
    }
}
