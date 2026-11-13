<?php

use Illuminate\Support\Facades\Route;
use Modules\Authentication\Http\Controllers\AuthController;

// Insights creates the Meet account of a new Insights user (signed with the shared secret; see InsightsSsoController::provision)
Route::post('insights/provision', [\Modules\Authentication\Http\Controllers\InsightsSsoController::class, 'provision'])->middleware('throttle:120,1')->name('insights.provision');

Route::prefix('v1/auth')->group(function () {
    Route::middleware('throttle:30,1,auth-api')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('verify-email', [AuthController::class, 'verifyEmail']);
        Route::post('resend-otp', [AuthController::class, 'resendOtp']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('two-factor/verify', [AuthController::class, 'verifyTwoFactor']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
    });
});
