<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\PushSubscriptionController;

// Core is a shared-kernel module (base traits/helpers used by other
// modules) - push subscriptions are the one genuinely cross-cutting
// API surface it owns, since PushNotificationService is called from
// Chat/Calls/Contacts alike.
Route::middleware(['auth:sanctum', 'throttle:60,1,push'])->prefix('v1/push-subscriptions')->group(function () {
    Route::post('/', [PushSubscriptionController::class, 'store']);
    Route::delete('/', [PushSubscriptionController::class, 'destroy']);
});
