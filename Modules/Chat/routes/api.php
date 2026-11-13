<?php

use Illuminate\Support\Facades\Route;
use Modules\Chat\Http\Controllers\ChatExportController;
use Modules\Chat\Http\Controllers\ConversationController;
use Modules\Chat\Http\Controllers\ConversationEncryptionController;
use Modules\Chat\Http\Controllers\LinkPreviewController;
use Modules\Chat\Http\Controllers\MessageController;

Route::middleware(['auth:sanctum', 'throttle:600,1,chat'])->prefix('v1')->group(function () {
    // FR-053's polling architecture legitimately generates a lot of
    // traffic per open tab: messages poll every ~1s (~60/min),
    // conversation meta every ~8s (~7.5/min), typing indicator bursts
    // while actively typing, plus the conversation list poll if another
    // tab is open. A single active user with two tabs open comfortably
    // clears 120/min on normal use, which isn't abuse - it's the
    // documented polling design. This limit is sized for that, not for
    // the request *count* alone; abuse protection here comes from
    // requiring Sanctum auth, not from a tight per-minute ceiling.

    // RAS-2 API17-21, API32-36 - conversations
    Route::post('conversations', [ConversationController::class, 'store']);
    Route::get('conversations', [ConversationController::class, 'index']);
    // Registered before {conversation} below - otherwise "unread-count"
    // would be captured as a conversation-id route-model-binding attempt.
    Route::get('conversations/unread-count', [ConversationController::class, 'unreadCount']);
    Route::get('conversations/{conversation}', [ConversationController::class, 'show']);
    Route::post('conversations/{conversation}/archive', [ConversationController::class, 'archive']);
    Route::delete('conversations/{conversation}', [ConversationController::class, 'destroy']);
    Route::post('conversations/{conversation}/read', [ConversationController::class, 'markRead']);
    Route::post('conversations/{conversation}/delivered', [MessageController::class, 'markDelivered']);
    Route::post('conversations/{conversation}/typing', [ConversationController::class, 'typing']);
    Route::post('conversations/{conversation}/draft', [ConversationController::class, 'saveDraft']);
    Route::delete('conversations/{conversation}/draft', [ConversationController::class, 'deleteDraft']);
    Route::patch('conversations/{conversation}/settings', [ConversationController::class, 'updateSettings']);
    Route::patch('conversations/{conversation}/disappearing-messages', [ConversationController::class, 'updateDisappearingMessages']);
    Route::get('conversations/{conversation}/wallpaper', [ConversationController::class, 'wallpapers']);
    Route::get('conversations/{conversation}/export', ChatExportController::class);
    Route::get('conversations/{conversation}/pinned', [MessageController::class, 'pinned']);
    Route::get('conversations/{conversation}/message-statuses', [MessageController::class, 'statuses']);
    Route::get('conversations/{conversation}/message-reactions', [MessageController::class, 'reactionUpdates']);
    Route::get('conversations/{conversation}/encryption', [ConversationEncryptionController::class, 'status']);
    Route::get('conversations/{conversation}/encryption/keys/{keyVersion}', [ConversationEncryptionController::class, 'keyMaterial']);

    // WhatsApp-style link preview - a generic utility, not scoped to any
    // one conversation (matches how the client calls it: as soon as it
    // spots a URL in a message body, regardless of which chat it's in).
    Route::get('link-preview', [LinkPreviewController::class, 'show']);

    // RAS-2 API22-31 - messages
    Route::post('messages', [MessageController::class, 'store']);
    Route::get('messages/starred', [MessageController::class, 'starred']);
    Route::get('messages/{conversation}', [MessageController::class, 'index']);
    Route::get('messages/{message}/info', [MessageController::class, 'info']);
    Route::patch('messages/{message}', [MessageController::class, 'update']);
    Route::delete('messages/{message}', [MessageController::class, 'destroy']);
    Route::post('messages/{message}/reaction', [MessageController::class, 'react']);
    Route::delete('messages/{message}/reaction', [MessageController::class, 'removeReaction']);
    Route::post('messages/{message}/reply', [MessageController::class, 'reply']);
    Route::post('messages/{message}/forward', [MessageController::class, 'forward']);
    Route::post('messages/{message}/star', [MessageController::class, 'star']);
    Route::post('messages/{message}/pin', [MessageController::class, 'pin']);
    Route::post('conversations/{conversation}/messages/migrate-to-encrypted', [MessageController::class, 'migrateToEncrypted']);
});
