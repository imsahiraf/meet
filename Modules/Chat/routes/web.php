<?php

use Illuminate\Support\Facades\Route;
use Modules\Chat\Http\Controllers\BroadcastListWebController;
use Modules\Chat\Http\Controllers\ChatWebController;

Route::middleware('auth')->prefix('chat')->group(function () {
    Route::get('/', [ChatWebController::class, 'index'])->name('chat.index');
    Route::get('archived', [ChatWebController::class, 'archived'])->name('chat.archived');
    // Registered before {conversation} below - a literal "starred"
    // segment would otherwise be attempted as a conversation-id binding.
    Route::get('starred', [ChatWebController::class, 'starred'])->name('chat.starred');
    Route::get('start/{participantId}', [ChatWebController::class, 'start'])->name('chat.start');
    Route::post('{conversation}/archive', [ChatWebController::class, 'archive'])->name('chat.archive');
    Route::post('{conversation}/mute', [ChatWebController::class, 'mute'])->name('chat.mute');
    Route::post('{conversation}/leave', [ChatWebController::class, 'leave'])->name('chat.leave');
    Route::get('{conversation}', [ChatWebController::class, 'show'])->name('chat.show');
});

Route::middleware('auth')->prefix('broadcast-lists')->name('broadcast-lists.')->group(function () {
    Route::get('/', [BroadcastListWebController::class, 'index'])->name('index');
    Route::get('create', [BroadcastListWebController::class, 'create'])->name('create');
    Route::post('/', [BroadcastListWebController::class, 'store'])->name('store');
    Route::get('{broadcastList}', [BroadcastListWebController::class, 'show'])->name('show');
    Route::delete('{broadcastList}', [BroadcastListWebController::class, 'destroy'])->name('destroy');
    Route::post('{broadcastList}/send', [BroadcastListWebController::class, 'send'])->name('send');
});
