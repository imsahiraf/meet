<?php

use Illuminate\Support\Facades\Route;
use Modules\Meetings\Http\Controllers\MeetingWebController;

// FR-321 guest join links are public - no auth middleware.
Route::get('j/{code}', [MeetingWebController::class, 'guestLanding'])->name('meetings.guest-landing');

// Guest-created meetings (no login, Google-Meet style) - registered
// BEFORE the {guestSession:session_token} wildcard below, since "new"
// would otherwise be swallowed by that route's implicit model binding.
Route::middleware(['throttle:20,1,meetings-guest-create'])->group(function () {
    Route::get('meetings/guest/new', [MeetingWebController::class, 'guestCreateForm'])->name('meetings.guest-create-form');
    Route::post('meetings/guest/new', [MeetingWebController::class, 'guestCreate'])->name('meetings.guest-create');
});

Route::get('meetings/guest/{guestSession:session_token}', [MeetingWebController::class, 'guestRoom'])->name('meetings.guest-room');

Route::middleware(['auth'])->group(function () {
    Route::get('meetings', [MeetingWebController::class, 'index'])->name('meetings.index');
    Route::get('meetings/history', [MeetingWebController::class, 'history'])->name('meetings.history');
    // Registered before {meeting} below - otherwise "calendar" would
    // be swallowed as a meeting-id route-model-binding attempt.
    Route::get('meetings/calendar', [MeetingWebController::class, 'calendar'])->name('meetings.calendar');
    Route::get('meetings/schedule', [MeetingWebController::class, 'createForm'])->name('meetings.create');
    Route::post('meetings/schedule', [MeetingWebController::class, 'store'])->name('meetings.store');
    Route::post('meetings/instant', [MeetingWebController::class, 'instant'])->name('meetings.instant');
    Route::get('meetings/{meeting}', [MeetingWebController::class, 'show'])->name('meetings.show');
});
