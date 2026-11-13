<?php

use Illuminate\Support\Facades\Route;
use Modules\Authentication\Http\Controllers\SessionController;
use Modules\User\Http\Controllers\ProfileController;

Route::prefix('v1/users')->middleware(['auth:sanctum'])->group(function () {
    Route::middleware('throttle:60,1,user-profile')->group(function () {
        // RAS-2 API1/API2 - profile bundle
        Route::get('profile', [ProfileController::class, 'show']);
        Route::put('profile', [ProfileController::class, 'updateProfile']);

        // RAS-2 API3/API4
        Route::patch('preferences', [ProfileController::class, 'updatePreferences']);
        Route::patch('privacy', [ProfileController::class, 'updatePrivacy']);

        // RAS-2 API5/API6 - active device/session management
        Route::get('devices', [SessionController::class, 'index']);
        Route::delete('devices/{deviceId}', [SessionController::class, 'destroy']);
    });

    // FR-032 - presence heartbeat. Fires automatically from every
    // authenticated page (every 20s, plus once per page load), not on
    // user action, so it needs its own generous budget rather than
    // sharing one with occasional profile/settings edits - a handful
    // of open tabs alone clears 60/min.
    Route::middleware('throttle:600,1,presence')->patch('presence', [ProfileController::class, 'updatePresence']);
});
