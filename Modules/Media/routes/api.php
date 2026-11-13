<?php

use Illuminate\Support\Facades\Route;
use Modules\Media\Http\Controllers\MediaController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    // Uploads are a real mutation worth limiting; 60/min is plenty for
    // that alone.
    Route::middleware('throttle:60,1,media-upload')->post('media', [MediaController::class, 'store']);

    // Serving files is not: a single chat thread with a handful of
    // images/documents already issues one GET per file on every fresh
    // page load (normal browser behavior, not abuse), so a tight limit
    // here breaks ordinary use of an image-heavy conversation rather
    // than stopping any real attack - auth:sanctum is the actual gate.
    Route::middleware('throttle:600,1,media-download')->get('media/{media}', [MediaController::class, 'show']);
});
