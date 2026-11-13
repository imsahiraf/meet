<?php

use Illuminate\Support\Facades\Route;
use Modules\Meetings\Http\Controllers\CalendarController;
use Modules\Meetings\Http\Controllers\GuestMeetingController;
use Modules\Meetings\Http\Controllers\MeetingController;
use Modules\Meetings\Http\Controllers\MeetingExtrasController;
use Modules\Meetings\Http\Controllers\MeetingSignalingController;

// FR-326 guest security - guests aren't Sanctum-authenticated; every
// action is scoped by their unguessable session_token instead.
Route::middleware(['throttle:120,1,meetings-guest'])->prefix('v1/meetings/guest')->group(function () {
    Route::get('{guestSession}/status', [GuestMeetingController::class, 'status']);
    Route::get('{guestSession}/roster', [GuestMeetingController::class, 'roster']);
    Route::patch('{guestSession}/media', [GuestMeetingController::class, 'media']);
    Route::post('{guestSession}/leave', [GuestMeetingController::class, 'leave']);
    Route::post('{guestSession}/lock', [GuestMeetingController::class, 'lock']);
    Route::post('{guestSession}/screen-share/start', [GuestMeetingController::class, 'startScreenShare'])->middleware('feature:screen_share');
    Route::post('{guestSession}/screen-share/stop', [GuestMeetingController::class, 'stopScreenShare']);
    Route::post('{guestSession}/offer', [GuestMeetingController::class, 'offer']);
    Route::post('{guestSession}/answer', [GuestMeetingController::class, 'answer']);
    Route::post('{guestSession}/ice', [GuestMeetingController::class, 'ice']);
    Route::get('{guestSession}/signals', [GuestMeetingController::class, 'poll']);
    Route::get('{guestSession}/events', [MeetingExtrasController::class, 'guestEvents']);
    Route::post('{guestSession}/captions', [MeetingExtrasController::class, 'guestCaption'])->middleware('feature:captions');
});

// FR-316 - the initial guest join request itself is also unauthenticated
// (a guest has no Sanctum session yet at this point either).
Route::middleware(['throttle:60,1,meetings-guest-join'])->prefix('v1/meetings')->group(function () {
    Route::post('{meeting}/guest', [MeetingController::class, 'guestJoin'])->middleware('feature:guest_mode');
});

Route::middleware(['auth:sanctum', 'throttle:600,1,meetings'])->prefix('v1/meetings')->group(function () {
    Route::get('lookup', [MeetingController::class, 'lookup']);
    Route::get('history', [MeetingController::class, 'history']);
    Route::get('upcoming', [MeetingController::class, 'upcoming']);

    Route::post('instant', [MeetingController::class, 'instant']);
    Route::post('/', [MeetingController::class, 'schedule']);

    Route::get('{meeting}', [MeetingController::class, 'show']);
    Route::delete('{meeting}', [MeetingController::class, 'cancel']);
    Route::post('{meeting}/join', [MeetingController::class, 'join']);
    Route::post('{meeting}/leave', [MeetingController::class, 'leave']);
    Route::post('{meeting}/end', [MeetingController::class, 'end']);
    Route::post('{meeting}/lock', [MeetingController::class, 'lock']);
    Route::post('{meeting}/raise-hand', [MeetingController::class, 'raiseHand']);
    Route::patch('{meeting}/media', [MeetingController::class, 'media']);

    Route::get('{meeting}/waiting-room', [MeetingController::class, 'waitingRoom']);
    Route::get('{meeting}/waiting-room/mine', [MeetingController::class, 'myWaitingStatus']);
    Route::post('{meeting}/waiting-room/{entry}/admit', [MeetingController::class, 'admit']);
    Route::post('{meeting}/waiting-room/{entry}/reject', [MeetingController::class, 'rejectWaiting']);

    Route::get('{meeting}/participants', [MeetingController::class, 'roster']);
    Route::post('{meeting}/participants/{participant}/mute', [MeetingController::class, 'mute']);
    Route::post('{meeting}/participants/{participant}/remove', [MeetingController::class, 'remove']);
    Route::post('{meeting}/participants/{participant}/co-host', [MeetingController::class, 'makeCoHost']);
    Route::post('{meeting}/participants/{participant}/revoke-co-host', [MeetingController::class, 'revokeCoHost']);
    Route::post('{meeting}/participants/{participant}/transfer-host', [MeetingController::class, 'transferHost']);

    Route::post('{meeting}/screen-share/start', [MeetingController::class, 'startScreenShare'])->middleware('feature:screen_share');
    Route::post('{meeting}/screen-share/stop', [MeetingController::class, 'stopScreenShare']);

    Route::post('{meeting}/offer', [MeetingSignalingController::class, 'offer']);
    Route::post('{meeting}/answer', [MeetingSignalingController::class, 'answer']);
    Route::post('{meeting}/ice', [MeetingSignalingController::class, 'ice']);
    Route::get('{meeting}/signals', [MeetingSignalingController::class, 'poll']);

    // captions, recording, breakout rooms, summary (owner 2026-11-06)
    Route::get('{meeting}/events', [MeetingExtrasController::class, 'events']);
    Route::post('{meeting}/captions', [MeetingExtrasController::class, 'caption'])->middleware('feature:captions');
    Route::post('{meeting}/recording', [MeetingExtrasController::class, 'recording'])->middleware('feature:recording');
    Route::post('{meeting}/recordings', [MeetingExtrasController::class, 'upload'])->middleware('feature:recording');
    Route::get('{meeting}/recordings', [MeetingExtrasController::class, 'recordings']);
    Route::get('{meeting}/recordings/{recording}', [MeetingExtrasController::class, 'download'])->name('meetings.recordings.download');
    Route::post('{meeting}/breakouts', [MeetingExtrasController::class, 'openBreakouts'])->middleware('feature:breakouts');
    Route::post('{meeting}/breakouts/close', [MeetingExtrasController::class, 'closeBreakouts']);
    Route::get('{meeting}/summary', [MeetingExtrasController::class, 'summary']);
    Route::post('{meeting}/summary', [MeetingExtrasController::class, 'makeSummary'])->middleware('feature:ai_features');
});

Route::middleware(['auth:sanctum', 'throttle:120,1,calendar'])->prefix('v1/calendar')->group(function () {
    Route::get('events', [CalendarController::class, 'index']);
    Route::post('events', [CalendarController::class, 'store']);
    Route::delete('events/{event}', [CalendarController::class, 'destroy']);
    Route::post('events/{event}/rsvp', [CalendarController::class, 'rsvp']);
    Route::get('events/{event}/ics', [CalendarController::class, 'ics']);
    Route::patch('events/{event}/reschedule', [CalendarController::class, 'reschedule']);
});
