<?php

use Illuminate\Support\Facades\Route;
use Modules\Contacts\Http\Controllers\BlockController;
use Modules\Contacts\Http\Controllers\ContactController;
use Modules\Contacts\Http\Controllers\ContactRequestController;
use Modules\Contacts\Http\Controllers\UserSearchController;

Route::middleware(['auth:sanctum', 'throttle:60,1,contacts'])->prefix('v1')->group(function () {
    // RAS-2 API7
    Route::get('users/search', [UserSearchController::class, 'index']);

    // RAS-2 API13/API14
    Route::post('users/{userId}/block', [BlockController::class, 'store']);
    Route::delete('users/{userId}/block', [BlockController::class, 'destroy']);
    Route::get('users/blocked', [BlockController::class, 'index']);

    // RAS-2 API8-10 + cancel
    Route::post('contacts/request', [ContactRequestController::class, 'store']);
    Route::get('contacts/requests', [ContactRequestController::class, 'index']);
    Route::post('contacts/request/{contactRequest}/accept', [ContactRequestController::class, 'accept']);
    Route::post('contacts/request/{contactRequest}/reject', [ContactRequestController::class, 'reject']);
    Route::delete('contacts/request/{contactRequest}', [ContactRequestController::class, 'cancel']);

    // RAS-2 API11/API12/API15/API16
    Route::get('contacts', [ContactController::class, 'index']);
    Route::delete('contacts/{contactId}', [ContactController::class, 'destroy']);
    Route::post('contacts/{contactId}/favorite', [ContactController::class, 'favorite']);
    Route::delete('contacts/{contactId}/favorite', [ContactController::class, 'unfavorite']);
});
