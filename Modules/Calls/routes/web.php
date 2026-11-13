<?php

use Illuminate\Support\Facades\Route;
use Modules\Calls\Http\Controllers\CallHistoryWebController;

Route::middleware('auth')->group(function () {
    Route::get('calls/history', [CallHistoryWebController::class, 'index'])->name('calls.history');
});
