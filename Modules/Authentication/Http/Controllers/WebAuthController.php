<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Authentication\Exceptions\AccountNotVerifiedException;
use Modules\Authentication\Exceptions\AccountSuspendedException;
use Modules\Authentication\Exceptions\InvalidCredentialsException;
use Modules\Authentication\Exceptions\InvalidOtpException;
use Modules\Authentication\Exceptions\InvalidResetTokenException;
use Modules\Authentication\Exceptions\InvalidTwoFactorChallengeException;
use Modules\Authentication\Exceptions\InvalidTwoFactorCodeException;
use Modules\Authentication\Exceptions\TooManyOtpRequestsException;
use Modules\Authentication\Http\Requests\ForgotPasswordRequest;
use Modules\Authentication\Http\Requests\LoginRequest;
use Modules\Authentication\Http\Requests\RegisterRequest;
use Modules\Authentication\Http\Requests\ResendOtpRequest;
use Modules\Authentication\Http\Requests\ResetPasswordRequest;
use Modules\Authentication\Http\Requests\VerifyEmailRequest;
use Modules\Authentication\Http\Requests\VerifyTwoFactorRequest;
use Modules\Authentication\Services\AccountSwitcherService;
use Modules\Authentication\Services\AuthenticationService;

class WebAuthController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly AccountSwitcherService $accountSwitcher,
    ) {
    }

    public function showLogin(Request $request): View
    {
        return view('authentication::login', [
            'switchableAccounts' => $this->accountSwitcher->list($request),
        ]);
    }

    /**
     * FR-004 - session-based login for the Blade UI. If the account
     * has 2FA enabled, no session is established yet - the user is
     * redirected to the code-entry challenge screen instead.
     */
    public function login(LoginRequest $request): RedirectResponse
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

        return redirect()->intended(route('chat.index'));
    }

    public function showTwoFactorChallenge(Request $request): View
    {
        return view('authentication::two-factor-challenge', ['challenge' => $request->query('challenge')]);
    }

    public function verifyTwoFactorChallenge(VerifyTwoFactorRequest $request): RedirectResponse
    {
        try {
            $this->auth->completeTwoFactorLoginForWeb($request, $request->string('challenge_token'), $request->string('code'), $request->boolean('remember_device'));
        } catch (InvalidTwoFactorChallengeException $e) {
            return redirect()->route('login')->withErrors(['login' => $e->getMessage()]);
        } catch (InvalidTwoFactorCodeException $e) {
            return back()->withErrors(['code' => $e->getMessage()])->withInput();
        }

        return redirect()->intended(route('chat.index'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->auth->logoutWeb($request);

        return redirect()->route('login');
    }

    public function logoutAll(Request $request): RedirectResponse
    {
        $this->auth->logoutAllAccountsWeb($request);

        return redirect()->route('login');
    }

    public function showRegister(): View
    {
        return view('authentication::register');
    }

    /**
     * FR-001 - registration then redirect to email verification.
     */
    public function register(RegisterRequest $request): RedirectResponse
    {
        $user = $this->auth->register($request->toDto());

        return redirect()->route('verify-email.show', ['email' => $user->email])
            ->with('status', 'Registration successful. Check your email for a verification code.');
    }

    public function showVerifyEmail(Request $request): View
    {
        return view('authentication::verify-email', ['email' => $request->query('email')]);
    }

    /**
     * FR-002 - verify OTP then automatically log the user in (per SRS
     * acceptance criteria: "User is automatically logged in").
     */
    public function verifyEmail(VerifyEmailRequest $request): RedirectResponse
    {
        try {
            $user = $this->auth->verifyEmail($request->string('email'), $request->string('otp'));
        } catch (InvalidOtpException $e) {
            return back()->withErrors(['otp' => $e->getMessage()])->withInput();
        }

        $this->auth->establishWebSession($user, $request);

        return redirect()->route('chat.index')->with('status', 'Email verified. Welcome to Meet!');
    }

    /**
     * FR-003 - resend OTP from the verify-email screen.
     */
    public function resendOtp(ResendOtpRequest $request): RedirectResponse
    {
        try {
            $this->auth->resendOtp($request->string('email'));
        } catch (TooManyOtpRequestsException $e) {
            return back()->withErrors(['otp' => $e->getMessage()]);
        }

        return back()->with('status', 'A new verification code has been sent.');
    }

    public function showForgotPassword(): View
    {
        return view('authentication::forgot-password');
    }

    public function forgotPassword(ForgotPasswordRequest $request): RedirectResponse
    {
        $this->auth->forgotPassword($request->string('email'));

        return back()->with('status', 'If that email exists, a password reset link has been sent.');
    }

    public function showResetPassword(Request $request): View
    {
        return view('authentication::reset-password', ['token' => $request->query('token')]);
    }

    public function resetPassword(ResetPasswordRequest $request): RedirectResponse
    {
        try {
            $this->auth->resetPassword($request->string('token'), $request->string('password'));
        } catch (InvalidResetTokenException $e) {
            return back()->withErrors(['token' => $e->getMessage()]);
        }

        return redirect()->route('login')->with('status', 'Password reset successfully. Please log in.');
    }
}
