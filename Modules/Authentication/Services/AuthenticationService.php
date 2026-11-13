<?php

namespace Modules\Authentication\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Modules\Authentication\DTO\RegisterUserData;
use Modules\Authentication\Events\EmailVerified;
use Modules\Authentication\Events\PasswordResetRequested;
use Modules\Authentication\Events\UserLoggedIn;
use Modules\Authentication\Events\UserRegistered;
use Modules\Authentication\Exceptions\AccountNotVerifiedException;
use Modules\Authentication\Exceptions\AccountSuspendedException;
use Modules\Authentication\Exceptions\InvalidCredentialsException;
use Modules\Authentication\Exceptions\InvalidOtpException;
use Modules\Authentication\Exceptions\InvalidResetTokenException;
use Modules\Authentication\Exceptions\TooManyOtpRequestsException;
use Modules\Authentication\Models\User;
use Modules\Authentication\Repositories\EmailOtpRepository;
use Modules\Authentication\Repositories\LoginAttemptRepository;
use Modules\Authentication\Repositories\PasswordResetTokenRepository;
use Modules\Authentication\Repositories\UserRepository;
use Modules\Authentication\Repositories\UserSessionRepository;
use Modules\User\Models\UserPresence;

class AuthenticationService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EmailOtpRepository $otps,
        private readonly PasswordResetTokenRepository $resetTokens,
        private readonly UserSessionRepository $sessions,
        private readonly LoginAttemptRepository $loginAttempts,
        private readonly TwoFactorService $twoFactor,
        private readonly AccountSwitcherService $accountSwitcher,
    ) {
    }

    /**
     * FR-001 / RAS-1 API1 - create the account, generate the mandatory
     * email OTP, and fire UserRegistered so mail + default profile rows
     * are created asynchronously.
     */
    public function register(RegisterUserData $data): User
    {
        if ($this->users->existsByEmail($data->email)) {
            throw ValidationException::withMessages(['email' => ['Email already exists.']]);
        }

        if ($this->users->existsByPhone($data->phone)) {
            throw ValidationException::withMessages(['phone' => ['Phone already exists.']]);
        }

        $user = $this->users->create(new RegisterUserData(
            fullName: $data->fullName,
            email: $data->email,
            phone: $data->phone,
            password: Hash::make($data->password),
        ));

        $otp = $this->otps->generate($user->email, 'registration', $user);

        UserRegistered::dispatch($user, $otp->otp);

        return $user;
    }

    /**
     * FR-002 / RAS-1 API2 - validate the OTP and activate the account.
     */
    public function verifyEmail(string $email, string $code): User
    {
        $user = $this->users->findByEmail($email);

        if (! $user) {
            throw new InvalidOtpException;
        }

        $otp = $this->otps->findPending($email, 'registration');

        if (! $otp) {
            throw new InvalidOtpException;
        }

        if ($otp->isExpired()) {
            throw new InvalidOtpException('This OTP has expired. Please request a new one.');
        }

        if ($otp->otp !== $code) {
            $this->otps->incrementAttempts($otp);

            throw new InvalidOtpException;
        }

        $this->otps->markVerified($otp);
        $this->users->markEmailVerified($user);

        EmailVerified::dispatch($user->fresh());

        return $user->fresh();
    }

    /**
     * FR-003 / RAS-1 API3 - issue a new OTP, enforcing cooldown and
     * daily resend limits.
     */
    public function resendOtp(string $email): void
    {
        $config = config('authentication.otp');
        $user = $this->users->findByEmail($email);

        $pending = $this->otps->findPending($email, 'registration');

        if ($pending && $pending->created_at->diffInSeconds(now()) < $config['resend_cooldown_seconds']) {
            throw new TooManyOtpRequestsException('Please wait before requesting another OTP.');
        }

        if ($this->otps->countResendsToday($email, 'registration') >= $config['max_resend_per_day']) {
            throw new TooManyOtpRequestsException('Daily OTP resend limit reached.');
        }

        $otp = $this->otps->generate($email, 'registration', $user);

        if ($user) {
            UserRegistered::dispatch($user, $otp->otp);
        }
    }

    /**
     * FR-004 / RAS-1 API4 - authenticate by email or phone, reject
     * unverified/suspended accounts, record the attempt. Shared by both
     * the API (token) and web (session) login paths below.
     */
    private function authenticate(Request $request, string $login, string $password): User
    {
        $user = $this->users->findByEmailOrPhone($login);

        if (! $user || ! $user->password || ! Hash::check($password, $user->password)) {
            $this->loginAttempts->record($request, $login, 'failed', $user, 'invalid_credentials');

            throw new InvalidCredentialsException;
        }

        $this->assertUserCanLogin($request, $login, $user);

        return $user;
    }

    /**
     * The suspended/unverified checks + attempt logging shared by
     * password login (authenticate() above, credential already
     * verified) and SSO login (authenticateViaSso() below, identity
     * already vouched for by the provider instead of a password) - a
     * suspended or unverified account must not be able to sign back in
     * through either door.
     */
    private function assertUserCanLogin(Request $request, string $login, User $user): void
    {
        if (in_array($user->status, ['inactive', 'suspended'], true)) {
            $this->loginAttempts->record($request, $login, 'blocked', $user, 'account_' . $user->status);

            throw new AccountSuspendedException;
        }

        if (! $user->email_verified_at) {
            $this->loginAttempts->record($request, $login, 'blocked', $user, 'email_not_verified');

            throw new AccountNotVerifiedException;
        }

        $this->loginAttempts->record($request, $login, 'success', $user);
        $this->users->recordLogin($user);
    }

    /**
     * SSO login - the provider (Google, etc.) already proved the
     * user's identity, so there's no password to check here, just the
     * same suspended/unverified gate every other login path enforces.
     */
    public function authenticateViaSso(Request $request, User $user): User
    {
        $this->assertUserCanLogin($request, $user->email, $user);

        return $user;
    }

    /**
     * API login - issues a Sanctum bearer token for mobile/PWA clients.
     * If the account has 2FA confirmed, no token is issued yet - a
     * challenge is created instead and the caller must POST the code
     * to the two-factor verify endpoint to actually get a token.
     */
    public function login(Request $request, string $login, string $password): array
    {
        $user = $this->authenticate($request, $login, $password);

        if ($this->twoFactor->isEnabledFor($user)) {
            $challenge = $this->twoFactor->createChallenge($user, false);

            return ['requires_2fa' => true, 'challenge_token' => $challenge->uuid];
        }

        return array_merge(['requires_2fa' => false], $this->issueApiToken($user, $request));
    }

    /**
     * The actual Sanctum-token-issuance tail, shared by login() (no
     * 2FA) and completeTwoFactorLoginForApi() (2FA challenge verified).
     */
    private function issueApiToken(User $user, Request $request): array
    {
        /** @var NewAccessToken $token */
        $token = $user->createToken('meet-' . Str::random(8));

        $session = $this->sessions->createFromRequest($user, $request, (string) $token->accessToken->id);

        UserLoggedIn::dispatch($user, $session);

        return [
            'token' => $token->plainTextToken,
            'user' => $user,
        ];
    }

    public function completeTwoFactorLoginForApi(Request $request, string $challengeToken, string $code): array
    {
        $challenge = $this->twoFactor->verifyChallenge($challengeToken, $code);

        return array_merge(['requires_2fa' => false], $this->issueApiToken($challenge->user, $request));
    }

    /**
     * Web login - establishes a session-based (guard: web) login for the
     * Blade UI, tracked in user_sessions by the Laravel session ID
     * instead of a Sanctum token ID. If the account has 2FA confirmed,
     * no session is established yet - the caller must redirect to the
     * two-factor challenge page instead.
     */
    public function loginForWeb(Request $request, string $login, string $password): array
    {
        $user = $this->authenticate($request, $login, $password);

        return $this->establishSessionOrTwoFactorChallenge($user, $request, $request->boolean('remember'));
    }

    /**
     * SSO web login - same 2FA-or-session branch as loginForWeb(), just
     * reached after authenticateViaSso() instead of a password check.
     * A linked Google account does not bypass 2FA: 2FA protects this
     * app's own account regardless of how the sign-in got here, unlike
     * a passkey (which is itself possession + biometric verification -
     * already a second factor by construction).
     */
    public function loginForWebViaSso(Request $request, User $user): array
    {
        return $this->establishSessionOrTwoFactorChallenge($user, $request, remember: true);
    }

    /**
     * Shared by loginForWeb() and loginForWebViaSso(): establishes a
     * real session immediately, or - if the account has 2FA confirmed
     * AND this isn't a device already trusted via "remember this
     * device" on a previous challenge - creates a challenge instead
     * and defers the session until it's verified. Web-only for now,
     * same as Passkeys/SSO - a bearer-token API client has no cookie
     * jar the same way, so login()/the API path never checks this.
     */
    private function establishSessionOrTwoFactorChallenge(User $user, Request $request, bool $remember): array
    {
        $isTrustedDevice = $this->twoFactor->isTrustedDevice($user, $request->cookie(TwoFactorService::TRUSTED_DEVICE_COOKIE));

        if ($this->twoFactor->isEnabledFor($user) && ! $isTrustedDevice) {
            $challenge = $this->twoFactor->createChallenge($user, $remember);

            return ['requires_2fa' => true, 'challenge_token' => $challenge->uuid];
        }

        $this->establishWebSession($user, $request, $remember);

        return ['requires_2fa' => false];
    }

    public function completeTwoFactorLoginForWeb(Request $request, string $challengeToken, string $code, bool $rememberDevice = false): void
    {
        $challenge = $this->twoFactor->verifyChallenge($challengeToken, $code);

        if ($rememberDevice) {
            $rawToken = $this->twoFactor->trustDevice($challenge->user, $request);
            Cookie::queue(TwoFactorService::TRUSTED_DEVICE_COOKIE, $rawToken, 60 * 24 * 30, '/', null, null, true, false, null);
        }

        $this->establishWebSession($challenge->user, $request, $challenge->remember);
    }

    /**
     * Shared session-establishment step, used by loginForWeb() (password
     * verified) and by the verify-email flow (OTP already proved
     * identity, per SRS FR-002's "user is automatically logged in").
     * $remember is an explicit parameter rather than always reading the
     * request - the login form's checkbox is now checked by default in
     * the HTML (so an unchecked submission still means "false" via
     * $request->boolean('remember')), while the verify-email flow has
     * no such checkbox at all and always remembers the new session.
     */
    public function establishWebSession(User $user, Request $request, bool $remember = true): void
    {
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        $session = $this->sessions->createFromRequest($user, $request, $request->session()->getId());

        UserLoggedIn::dispatch($user, $session);

        if ($remember) {
            // $user already carries the fresh remember_token in memory -
            // Auth::guard('web')->login() above sets it on this same
            // object before persisting it, standard Laravel behavior.
            $this->accountSwitcher->remember($request, $user);
        }
    }

    /**
     * Ends the session-based web login and closes its tracked session row.
     */
    public function logoutWeb(Request $request): void
    {
        $user = $this->terminateWebSession($request);

        // Laravel's own logout() above already rotates the user's
        // remember_token (it deliberately invalidates any "remember me"
        // cookie on an explicit logout) - the switcher entry's stored
        // token is now stale regardless, so drop it rather than leave a
        // dead "Continue as..." tile that would just fail if tapped.
        // Switching AWAY to a held account via the switcher itself
        // (loginUsingId) never goes through this method, so it never
        // forgets the outgoing account - only a real, explicit logout does.
        if ($user) {
            $this->accountSwitcher->forget($request, $user->id);
        }
    }

    /**
     * "Logout of all accounts" - same as logoutWeb() but also clears
     * every OTHER account held in this browser's switcher, not just the
     * one being logged out of. Each held account's own session/remember-
     * token elsewhere is untouched - this only clears local "Continue
     * as..." tiles, not a remote force-logout of those other accounts.
     */
    public function logoutAllAccountsWeb(Request $request): void
    {
        $this->terminateWebSession($request);
        $this->accountSwitcher->forgetAll($request);
    }

    private function terminateWebSession(Request $request): ?User
    {
        $user = $request->user();
        $sessionId = $request->session()->getId();

        $session = $user?->sessions()->where('session_id', $sessionId)->where('status', 'active')->first();
        $session && $this->sessions->terminate($session);

        $user && UserPresence::where('user_id', $user->id)->update(['presence_status' => 'offline']);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $user;
    }

    /**
     * RAS-1 API5 - revoke the current access token and close its session.
     */
    public function logout(User $user, ?string $tokenId): void
    {
        if ($tokenId) {
            $session = $user->sessions()->where('session_id', $tokenId)->where('status', 'active')->first();

            if ($session) {
                $this->sessions->terminate($session);
            }
        }

        $user->currentAccessToken()?->delete();
    }

    /**
     * FR-005 / RAS-1 API6 - issue a password reset token and email it.
     */
    public function forgotPassword(string $email): void
    {
        $user = $this->users->findByEmail($email);

        if (! $user) {
            // Do not reveal account existence; behave as if the email was sent.
            return;
        }

        $resetToken = $this->resetTokens->generate($user);

        PasswordResetRequested::dispatch($user, $resetToken);
    }

    /**
     * FR-006 / RAS-1 API7 - validate the reset token and update the password.
     */
    public function resetPassword(string $token, string $newPassword): void
    {
        $resetToken = $this->resetTokens->findActiveByToken($token);

        if (! $resetToken || $resetToken->isExpired()) {
            throw new InvalidResetTokenException;
        }

        $user = $resetToken->user;

        $this->users->updatePassword($user, Hash::make($newPassword));
        $this->resetTokens->markUsed($resetToken);
        $this->sessions->terminateAllForUser($user);
        $user->tokens()->delete();
    }

    /**
     * RAS-1 API10 - verify the current password and update it.
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw new InvalidCredentialsException('The current password is incorrect.');
        }

        $this->users->updatePassword($user, Hash::make($newPassword));
    }

    /**
     * Completes the forced-password-change flow an admin-issued
     * temporary password puts a user into (see
     * Modules\Admin\Services\UserManagementService::resetPassword()) -
     * no current-password check, since the user already proved
     * possession of the temporary password by logging in with it.
     */
    public function completeForcedPasswordChange(User $user, string $newPassword): void
    {
        $this->users->updatePassword($user, Hash::make($newPassword));
        $user->forceFill(['must_change_password' => false])->save();
    }

    /**
     * Self-service account deactivation - a soft delete (same
     * SoftDeletes trait/undo path Admin's User Management already
     * relies on), never a hard delete. Re-verifies the current
     * password first, matching changePassword()'s step-up pattern.
     */
    public function deactivateAccount(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw new InvalidCredentialsException('The current password is incorrect.');
        }

        $user->tokens()->delete();
        $this->sessions->terminateAllForUser($user);
        $user->delete();
    }
}
