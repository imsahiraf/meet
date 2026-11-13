<?php

use Illuminate\Support\Facades\Route;
use Modules\Whiteboard\Http\Controllers\GuestWhiteboardController;
use Modules\Whiteboard\Http\Controllers\WhiteboardController;

Route::middleware(['auth:sanctum', 'throttle:600,1,whiteboard'])->prefix('v1')->group(function () {
    Route::post('meetings/{meeting}/whiteboard', [WhiteboardController::class, 'open']);

    Route::prefix('whiteboards/{whiteboard}')->group(function () {
        Route::get('/', [WhiteboardController::class, 'show']);
        Route::patch('permission', [WhiteboardController::class, 'setPermission']);
        Route::post('pages', [WhiteboardController::class, 'addPage']);
        Route::delete('pages/{page}', [WhiteboardController::class, 'deletePage']);
        Route::get('pages/{page}/objects', [WhiteboardController::class, 'objects']);
        Route::post('pages/{page}/objects', [WhiteboardController::class, 'storeObject']);
        Route::post('pages/{page}/clear', [WhiteboardController::class, 'clearPage']);
        Route::delete('objects/{object}', [WhiteboardController::class, 'deleteObject']);
        Route::post('undo', [WhiteboardController::class, 'undo']);
        Route::post('redo', [WhiteboardController::class, 'redo']);
        Route::post('export', [WhiteboardController::class, 'export']);
    });
});

// Guests aren't Sanctum-authenticated (see Modules/Meetings/routes/api.php's
// own guest group) - every route here is scoped by {guestSession} instead,
// mirroring the block above 1:1.
Route::middleware(['throttle:600,1,whiteboard-guest'])->prefix('v1/meetings/guest/{guestSession}')->group(function () {
    Route::post('whiteboard', [GuestWhiteboardController::class, 'open']);

    Route::prefix('whiteboards/{whiteboard}')->group(function () {
        Route::get('/', [GuestWhiteboardController::class, 'show']);
        Route::patch('permission', [GuestWhiteboardController::class, 'setPermission']);
        Route::post('pages', [GuestWhiteboardController::class, 'addPage']);
        Route::delete('pages/{page}', [GuestWhiteboardController::class, 'deletePage']);
        Route::get('pages/{page}/objects', [GuestWhiteboardController::class, 'objects']);
        Route::post('pages/{page}/objects', [GuestWhiteboardController::class, 'storeObject']);
        Route::post('pages/{page}/clear', [GuestWhiteboardController::class, 'clearPage']);
        Route::delete('objects/{object}', [GuestWhiteboardController::class, 'deleteObject']);
        Route::post('undo', [GuestWhiteboardController::class, 'undo']);
        Route::post('redo', [GuestWhiteboardController::class, 'redo']);
        Route::post('export', [GuestWhiteboardController::class, 'export']);
    });
});
