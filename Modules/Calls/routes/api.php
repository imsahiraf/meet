<?php

use Illuminate\Support\Facades\Route;
use Modules\Calls\Http\Controllers\CallController;
use Modules\Calls\Http\Controllers\SignalingController;

Route::middleware(['auth:sanctum', 'throttle:600,1,calls'])->prefix('v1/calls')->group(function () {
    // Registered before {call} so "incoming"/"history" aren't captured
    // as a call-id wildcard.
    Route::get('incoming', [CallController::class, 'incoming']);
    Route::get('history', [CallController::class, 'history']);

    // RAS-4 API1-2
    Route::post('voice/start', [CallController::class, 'startVoice'])->middleware('feature:voice_calls');
    Route::post('video/start', [CallController::class, 'startVideo'])->middleware('feature:video_calls');

    Route::get('{call}', [CallController::class, 'show']);

    // RAS-4 API3-5
    Route::post('{call}/accept', [CallController::class, 'accept']);
    Route::post('{call}/reject', [CallController::class, 'reject']);
    Route::post('{call}/end', [CallController::class, 'end']);
    Route::patch('{call}/media', [CallController::class, 'updateMedia']);
    Route::post('{call}/add-member', [CallController::class, 'addMember']);

    // RAS-4 API7-9 + polling (see SignalingController::poll docblock)
    Route::post('{call}/offer', [SignalingController::class, 'offer']);
    Route::post('{call}/answer', [SignalingController::class, 'answer']);
    Route::post('{call}/ice', [SignalingController::class, 'ice']);
    Route::post('{call}/reaction', [SignalingController::class, 'reaction']);
    Route::post('{call}/screen-share', [SignalingController::class, 'screenShare']);
    Route::post('{call}/event', [SignalingController::class, 'event']);
    Route::get('{call}/signals', [SignalingController::class, 'poll']);
});
