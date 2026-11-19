<?php

use Illuminate\Support\Facades\Route;
use Modules\Authentication\Http\Controllers\AccountSwitcherController;
use Modules\Authentication\Http\Controllers\ForcePasswordChangeController;
use Modules\Authentication\Http\Controllers\InsightsLinkController;
use Modules\Authentication\Http\Controllers\InsightsSsoController;
use Modules\Authentication\Http\Controllers\PasskeyLoginController;
use Modules\Authentication\Http\Controllers\SsoController;
use Modules\Authentication\Http\Controllers\ToolsPassController;
use Modules\Authentication\Http\Controllers\WebAuthController;

Route::middleware('guest')->group(function () {
    // Viewing a form is not a security-sensitive action - no abuse
    // vector to rate limit here, and throttling it made even normal
    // multi-step flows (register -> verify -> login) burn through the
    // budget shared with the POST actions below.
    Route::get('login', [WebAuthController::class, 'showLogin'])->name('login');
    Route::get('register', [WebAuthController::class, 'showRegister'])->name('register');
    Route::get('verify-email', [WebAuthController::class, 'showVerifyEmail'])->name('verify-email.show');
    Route::get('forgot-password', [WebAuthController::class, 'showForgotPassword'])->name('password.request');
    Route::get('reset-password', [WebAuthController::class, 'showResetPassword'])->name('password.reset');
    Route::get('two-factor-challenge', [WebAuthController::class, 'showTwoFactorChallenge'])->name('two-factor.challenge.show');

    // 30/min still meaningfully slows down credential/OTP brute-forcing
    // (LoginAttempt records every try regardless) while not tripping
    // over itself during legitimate multi-step flows or multi-device use.
    Route::middleware('throttle:30,1,auth-web')->group(function () {
        Route::post('login', [WebAuthController::class, 'login'])->name('login.attempt');
        Route::post('register', [WebAuthController::class, 'register'])->name('register.attempt');
        Route::post('verify-email', [WebAuthController::class, 'verifyEmail'])->name('verify-email.attempt');
        Route::post('resend-otp', [WebAuthController::class, 'resendOtp'])->name('resend-otp');
        Route::post('forgot-password', [WebAuthController::class, 'forgotPassword'])->name('password.email');
        Route::post('reset-password', [WebAuthController::class, 'resetPassword'])->name('password.update');
        Route::post('two-factor-challenge', [WebAuthController::class, 'verifyTwoFactorChallenge'])->name('two-factor.challenge.verify');
        Route::get('passkey-login/options', [PasskeyLoginController::class, 'options'])->name('passkey.login-options');
        Route::post('passkey-login', [PasskeyLoginController::class, 'login'])->name('passkey.login');
        Route::get('auth/{provider}', [SsoController::class, 'redirect'])->name('sso.redirect');
        Route::get('auth/{provider}/callback', [SsoController::class, 'callback'])->name('sso.callback');
    });
});

// Signed one-time entry link from Insights by Meet (works whether or not someone is signed in here)
Route::get('sso/insights', [InsightsSsoController::class, 'enter'])->middleware('throttle:30,1,auth-web')->name('sso.insights');

// Free Meet tools on Insights by Meet: sign up / log in here, go back with a signed pass (see ToolsPassController)
Route::middleware(['auth', 'throttle:30,1,auth-web'])->get('tools/pass', ToolsPassController::class)->name('tools.pass');

Route::middleware('auth')->post('logout', [WebAuthController::class, 'logout'])->name('logout');
Route::middleware('auth')->post('logout-all', [WebAuthController::class, 'logoutAll'])->name('logout.all');

Route::middleware('auth')->group(function () {
    Route::get('settings/insights', [InsightsLinkController::class, 'show'])->name('insights.link');
    Route::delete('settings/insights/{id}', [InsightsLinkController::class, 'destroy'])->name('insights.unlink')->whereNumber('id');
    Route::get('force-password-change', [ForcePasswordChangeController::class, 'show'])->name('force-password-change.show');
    Route::post('force-password-change', [ForcePasswordChangeController::class, 'update'])->name('force-password-change.update');
});

// Not gated by 'auth' or 'guest' - reachable both from the login page's
// guest-facing "Continue as..." tiles and from an in-app "Switch
// account" action while already authenticated as someone else (see
// AccountSwitcherController's docblock).
Route::middleware('throttle:30,1,auth-web')->group(function () {
    Route::post('accounts/{userId}/switch', [AccountSwitcherController::class, 'switch'])->name('accounts.switch');
    Route::post('accounts/{userId}/forget', [AccountSwitcherController::class, 'forget'])->name('accounts.forget');
    Route::get('accounts/add', [AccountSwitcherController::class, 'showAdd'])->name('accounts.add.show');
    Route::post('accounts/add', [AccountSwitcherController::class, 'add'])->name('accounts.add');
});
