<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;
use Modules\Authentication\Exceptions\CannotUnlinkSoleLoginMethodException;
use Modules\Authentication\Exceptions\InvalidCredentialsException;
use Modules\Authentication\Exceptions\InvalidTwoFactorCodeException;
use Modules\Authentication\Http\Requests\ChangePasswordRequest;
use Modules\Authentication\Models\TrustedDevice;
use Modules\Authentication\Models\UserOauthIdentity;
use Modules\Authentication\Repositories\UserSessionRepository;
use Modules\Authentication\Services\AccountSwitcherService;
use Modules\Authentication\Services\AuthenticationService;
use Modules\Authentication\Services\SsoService;
use Modules\Authentication\Services\TwoFactorService;
use Modules\Chat\Models\ChatWallpaper;
use Modules\User\Http\Requests\UpdatePreferencesRequest;
use Modules\User\Http\Requests\UpdatePrivacyRequest;
use Modules\User\Http\Requests\UpdateProfileRequest;
use Modules\User\Services\UserProfileService;

class ProfileWebController extends Controller
{
    public function __construct(
        private readonly UserProfileService $profiles,
        private readonly UserSessionRepository $sessions,
        private readonly AuthenticationService $auth,
        private readonly TwoFactorService $twoFactor,
        private readonly SsoService $sso,
        private readonly AccountSwitcherService $accountSwitcher,
    ) {
    }

    /**
     * Settings hub - a WhatsApp-style list of categories, each its own
     * page, instead of every section stacked on one long scroll.
     */
    public function edit(): View
    {
        return view('user::profile.edit');
    }

    public function showInfo(Request $request): View
    {
        return view('user::profile.info', ['user' => $request->user()->load('profile')]);
    }


    public function showPreferences(Request $request): View
    {
        return view('user::profile.preferences', [
            'user' => $request->user()->load('preferences'),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    public function showNotifications(Request $request): View
    {
        return view('user::profile.notifications', [
            'user' => $request->user()->load('preferences'),
        ]);
    }

    public function showChatDefaults(Request $request): View
    {
        $user = $request->user()->load('preferences');

        return view('user::profile.chat-defaults', [
            'user' => $user,
            'wallpapers' => ChatWallpaper::where('is_system_default', true)
                ->orWhere('user_id', $user->id)
                ->get(['id', 'name']),
        ]);
    }

    public function showPrivacy(Request $request): View
    {
        return view('user::profile.privacy', [
            'user' => $request->user()->load('privacy'),
        ]);
    }

    /**
     * Account & Security sub-hub - each option (password, two-factor,
     * passkeys, connected accounts, devices, deactivation) is its own
     * dedicated page rather than one long stacked scroll, matching the
     * top-level Settings hub's pattern.
     */
    public function showSecurity(Request $request): View
    {
        return view('user::profile.security', [
            'otherSwitchableAccounts' => $this->accountSwitcher->list($request, $request->user()->id),
        ]);
    }

    public function showPassword(): View
    {
        return view('user::profile.password');
    }

    public function showTwoFactor(Request $request): View
    {
        $user = $request->user();

        return view('user::profile.two-factor', [
            'twoFactorEnabled' => $this->twoFactor->isEnabledFor($user),
            'twoFactorSetup' => $request->session()->get('two_factor_setup'),
        ]);
    }

    public function showPasskeys(Request $request): View
    {
        return view('user::profile.passkeys', [
            'passkeys' => $request->user()->passkeys()->latest()->get(),
        ]);
    }

    public function showConnectedAccounts(Request $request): View
    {
        return view('user::profile.connected-accounts', [
            'oauthIdentities' => $request->user()->oauthIdentities()->get(),
        ]);
    }

    public function showDeactivate(): View
    {
        return view('user::profile.deactivate');
    }

    /**
     * Profile photo (avatar) and cover photo. The photo is the one every screen and the apps show (users.profile_photo, also
     * kept on the profile as avatar); stored on the public disk under a random name, the old file removed.
     */
    public function updatePhoto(Request $request, string $kind): RedirectResponse
    {
        abort_unless(in_array($kind, ['avatar', 'cover'], true), 404);
        $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:' . ($kind === 'cover' ? 6144 : 4096), 'dimensions:min_width=64,min_height=64,max_width=8000,max_height=8000']]);
        $user = $request->user()->load('profile');
        $path = $request->file('photo')->store($kind === 'cover' ? 'covers' : 'avatars', 'public');
        $url = '/storage/' . $path; // relative: works on every address Meet answers on
        $this->removePhoto($user, $kind);
        if ($kind === 'avatar') {
            $user->forceFill(['profile_photo' => $url])->save();
            $user->profile?->forceFill(['avatar' => $url])->save();
        } else {
            $user->profile?->forceFill(['cover_photo' => $url])->save();
        }

        return back()->with('status', $kind === 'cover' ? 'Cover photo saved.' : 'Profile photo saved.');
    }

    public function deletePhoto(Request $request, string $kind): RedirectResponse
    {
        abort_unless(in_array($kind, ['avatar', 'cover'], true), 404);
        $user = $request->user()->load('profile');
        $this->removePhoto($user, $kind);
        if ($kind === 'avatar') {
            $user->forceFill(['profile_photo' => null])->save();
            $user->profile?->forceFill(['avatar' => null])->save();
        } else {
            $user->profile?->forceFill(['cover_photo' => null])->save();
        }

        return back()->with('status', $kind === 'cover' ? 'Cover photo removed.' : 'Profile photo removed.');
    }

    /** Delete the old file of this kind, only when it is one of ours on the public disk. */
    private function removePhoto($user, string $kind): void
    {
        $old = $kind === 'avatar' ? ($user->profile_photo ?: $user->profile?->avatar) : $user->profile?->cover_photo;
        $rel = $old ? (string) preg_replace('#^(https?://[^/]+)?/storage/#', '', $old, 1, $n) : '';
        if ($old && $n === 1) {
            if (preg_match('#^(avatars|covers)/[A-Za-z0-9._-]+$#', $rel)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($rel);
            }
        }
    }

    public function updateProfile(UpdateProfileRequest $request): RedirectResponse
    {
        $this->profiles->updateProfile($request->user(), $request->validated());

        return back()->with('status', 'Profile updated successfully.');
    }

    public function updatePreferences(UpdatePreferencesRequest $request): RedirectResponse
    {
        $this->profiles->updatePreferences($request->user(), $request->validated());

        return back()->with('status', 'Preferences updated successfully.');
    }

    public function updatePrivacy(UpdatePrivacyRequest $request): RedirectResponse
    {
        $this->profiles->updatePrivacy($request->user(), $request->validated());

        return back()->with('status', 'Privacy settings updated successfully.');
    }

    public function devices(Request $request): View
    {
        $sessions = $request->user()->sessions()->where('status', 'active')->latest('last_activity_at')->get();

        return view('user::profile.devices', ['sessions' => $sessions]);
    }

    public function revokeDevice(Request $request, string $deviceId): RedirectResponse
    {
        $session = $request->user()->sessions()->where('uuid', $deviceId)->where('status', 'active')->firstOrFail();

        $this->sessions->terminate($session);
        $request->user()->tokens()->where('id', $session->session_id)->delete();

        return back()->with('status', 'Device signed out.');
    }

    public function switchableAccounts(Request $request): View
    {
        return view('user::profile.switch-accounts', [
            'accounts' => $this->accountSwitcher->list($request, excludeUserId: $request->user()->id),
        ]);
    }

    public function trustedDevices(Request $request): View
    {
        return view('user::profile.trusted-devices', [
            'devices' => $this->twoFactor->trustedDevicesFor($request->user()),
        ]);
    }

    public function revokeTrustedDevice(Request $request, TrustedDevice $device): RedirectResponse
    {
        $this->twoFactor->revokeTrustedDevice($request->user(), $device);

        return back()->with('status', 'That device will be asked for a code again next time.');
    }

    public function pushSubscriptions(Request $request): View
    {
        $subscriptions = $request->user()->pushSubscriptions()->latest()->get();

        return view('user::profile.push-subscriptions', ['subscriptions' => $subscriptions]);
    }

    public function revokePushSubscription(Request $request, string $subscriptionId): RedirectResponse
    {
        $subscription = $request->user()->pushSubscriptions()->where('uuid', $subscriptionId)->firstOrFail();
        $subscription->delete();

        return back()->with('status', 'Push notifications turned off for that device.');
    }

    /**
     * A JSON branch exists alongside the normal full-page form post so
     * password.blade.php's script can AJAX-submit and show an inline
     * success message without a full page reload, falling back to a
     * real form submission (reusing the existing session-flashed error
     * display) on failure.
     */
    public function changePassword(ChangePasswordRequest $request): RedirectResponse|JsonResponse
    {
        try {
            $this->auth->changePassword($request->user(), $request->validated()['current_password'], $request->validated()['new_password']);
        } catch (InvalidCredentialsException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->withErrors(['current_password' => $e->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Password changed successfully.']);
        }

        return back()->with('status', 'Password changed successfully.');
    }

    public function deactivateAccount(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        try {
            $this->auth->deactivateAccount($request->user(), $request->input('password'));
        } catch (InvalidCredentialsException $e) {
            return back()->withErrors(['password' => $e->getMessage()]);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Your account has been deactivated.');
    }

    /**
     * Starts (or restarts) 2FA setup - stored in the session (not a
     * one-shot flash) so the QR code stays visible across a page
     * reload until the user actually confirms it with a real code.
     */
    public function enableTwoFactor(Request $request): RedirectResponse
    {
        $setup = $this->twoFactor->startSetup($request->user());
        $request->session()->put('two_factor_setup', $setup);

        return back()->with('status', 'Scan the QR code with your authenticator app, then enter a code to confirm.');
    }

    public function confirmTwoFactor(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        try {
            $recoveryCodes = $this->twoFactor->confirm($request->user(), $request->input('code'));
        } catch (InvalidTwoFactorCodeException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        $request->session()->forget('two_factor_setup');

        return back()
            ->with('status', 'Two-factor authentication is now enabled.')
            ->with('two_factor_recovery_codes', $recoveryCodes);
    }

    public function disableTwoFactor(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($request->input('password'), $request->user()->password)) {
            return back()->withErrors(['password' => 'The current password is incorrect.']);
        }

        $this->twoFactor->disable($request->user());

        return back()->with('status', 'Two-factor authentication disabled.');
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($request->input('password'), $request->user()->password)) {
            return back()->withErrors(['password' => 'The current password is incorrect.']);
        }

        $recoveryCodes = $this->twoFactor->regenerateRecoveryCodes($request->user());

        return back()
            ->with('status', 'New recovery codes generated. Save them somewhere safe.')
            ->with('two_factor_recovery_codes', $recoveryCodes);
    }

    /**
     * No password step-up needed to start registering a passkey - this
     * mirrors 2FA's startSetup(), which also asks for nothing until the
     * new credential/code is actually confirmed.
     */
    public function passkeyRegistrationOptions(Request $request, GenerateRegistrationOptions $generate): JsonResponse
    {
        $options = $generate($request->user());

        $request->session()->put('passkey.registration_options', WebAuthn::toJson($options));

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    /**
     * Returns JSON rather than the usual back()->with('status', ...)
     * redirect - this endpoint is only ever called via axios (never a
     * real form submit), and axios/XHR auto-follows a redirect
     * transparently, which would silently consume the one-shot session
     * flash before the page is actually reloaded to show it.
     */
    public function storePasskey(PasskeyRegistrationRequest $request, StorePasskey $store): JsonResponse
    {
        $passkey = $store(
            $request->user(),
            $request->string('name')->toString(),
            $request->credential(),
            $request->registrationOptions()
        );

        return response()->json(['id' => $passkey->id, 'name' => $passkey->name]);
    }

    /**
     * Removing a passkey requires the current password, matching the
     * same step-up convention as 2FA disable/regenerate.
     */
    public function destroyPasskey(Request $request, Passkey $passkey, DeletePasskey $delete): RedirectResponse
    {
        abort_unless($passkey->user_id === $request->user()->id, 403);

        $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($request->input('password'), $request->user()->password)) {
            return back()->withErrors(['password' => 'The current password is incorrect.']);
        }

        $delete($request->user(), $passkey);

        return back()->with('status', 'Passkey removed.');
    }

    /**
     * No password step-up here (unlike 2FA disable/passkey removal) -
     * unlinking a connected account only ever narrows the account's
     * login surface, it can't be exploited by a hijacked session to
     * gain anything. SsoService::unlink() itself still refuses to
     * leave the account with zero ways to sign back in.
     */
    public function unlinkOauthIdentity(Request $request, UserOauthIdentity $identity): RedirectResponse
    {
        abort_unless($identity->user_id === $request->user()->id, 403);

        try {
            $this->sso->unlink($request->user(), $identity);
        } catch (CannotUnlinkSoleLoginMethodException $e) {
            return back()->withErrors(['oauth' => $e->getMessage()]);
        }

        return back()->with('status', 'Connected account removed.');
    }
}
