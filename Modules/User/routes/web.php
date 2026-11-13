<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\ProfileWebController;

Route::middleware('auth')->prefix('profile')->group(function () {
    Route::get('/', [ProfileWebController::class, 'edit'])->name('profile.edit');

    Route::get('info', [ProfileWebController::class, 'showInfo'])->name('profile.info');
    Route::put('/', [ProfileWebController::class, 'updateProfile'])->name('profile.update');
    Route::post('photo/{kind}', [ProfileWebController::class, 'updatePhoto'])->where('kind', 'avatar|cover')->middleware('throttle:20,1')->name('profile.photo.update');
    Route::delete('photo/{kind}', [ProfileWebController::class, 'deletePhoto'])->where('kind', 'avatar|cover')->name('profile.photo.delete');

    Route::get('preferences', [ProfileWebController::class, 'showPreferences'])->name('profile.preferences.show');
    Route::patch('preferences', [ProfileWebController::class, 'updatePreferences'])->name('profile.preferences');

    Route::get('notifications', [ProfileWebController::class, 'showNotifications'])->name('profile.notifications');

    Route::get('chat-defaults', [ProfileWebController::class, 'showChatDefaults'])->name('profile.chat-defaults');

    Route::get('privacy', [ProfileWebController::class, 'showPrivacy'])->name('profile.privacy.show');
    Route::patch('privacy', [ProfileWebController::class, 'updatePrivacy'])->name('profile.privacy');

    Route::get('security', [ProfileWebController::class, 'showSecurity'])->name('profile.security');
    Route::get('devices', [ProfileWebController::class, 'devices'])->name('profile.devices');
    Route::delete('devices/{deviceId}', [ProfileWebController::class, 'revokeDevice'])->name('profile.devices.revoke');
    Route::get('push-subscriptions', [ProfileWebController::class, 'pushSubscriptions'])->name('profile.push-subscriptions');
    Route::delete('push-subscriptions/{subscriptionId}', [ProfileWebController::class, 'revokePushSubscription'])->name('profile.push-subscriptions.revoke');
    Route::get('password', [ProfileWebController::class, 'showPassword'])->name('profile.password.show');
    Route::put('password', [ProfileWebController::class, 'changePassword'])->name('profile.password');
    Route::get('deactivate', [ProfileWebController::class, 'showDeactivate'])->name('profile.deactivate.show');
    Route::delete('/', [ProfileWebController::class, 'deactivateAccount'])->name('profile.deactivate');

    Route::get('two-factor', [ProfileWebController::class, 'showTwoFactor'])->name('profile.two-factor.show');
    Route::post('two-factor', [ProfileWebController::class, 'enableTwoFactor'])->name('profile.two-factor.enable');
    Route::post('two-factor/confirm', [ProfileWebController::class, 'confirmTwoFactor'])->name('profile.two-factor.confirm');
    Route::delete('two-factor', [ProfileWebController::class, 'disableTwoFactor'])->name('profile.two-factor.disable');
    Route::post('two-factor/recovery-codes', [ProfileWebController::class, 'regenerateRecoveryCodes'])->name('profile.two-factor.recovery-codes');
    Route::get('trusted-devices', [ProfileWebController::class, 'trustedDevices'])->name('profile.trusted-devices');
    Route::delete('trusted-devices/{device}', [ProfileWebController::class, 'revokeTrustedDevice'])->name('profile.trusted-devices.revoke');
    Route::get('switch-accounts', [ProfileWebController::class, 'switchableAccounts'])->name('profile.switch-accounts');

    Route::get('passkeys', [ProfileWebController::class, 'showPasskeys'])->name('profile.passkeys.show');
    Route::get('passkeys/options', [ProfileWebController::class, 'passkeyRegistrationOptions'])->name('profile.passkeys.options');
    Route::post('passkeys', [ProfileWebController::class, 'storePasskey'])->name('profile.passkeys.store');
    Route::delete('passkeys/{passkey}', [ProfileWebController::class, 'destroyPasskey'])->name('profile.passkeys.destroy');

    Route::get('connected-accounts', [ProfileWebController::class, 'showConnectedAccounts'])->name('profile.oauth.show');
    Route::delete('oauth/{identity}', [ProfileWebController::class, 'unlinkOauthIdentity'])->name('profile.oauth.unlink');
});
