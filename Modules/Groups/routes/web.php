<?php

use Illuminate\Support\Facades\Route;
use Modules\Groups\Http\Controllers\CommunityWebController;
use Modules\Groups\Http\Controllers\GroupContentWebController;
use Modules\Groups\Http\Controllers\GroupsWebController;

Route::middleware('auth')->group(function () {
    Route::get('groups/join/{token}', [GroupsWebController::class, 'showJoinPage'])->name('groups.join.show');
    Route::post('groups/join/{token}', [GroupsWebController::class, 'joinViaInvite'])->name('groups.join');

    Route::prefix('communities')->name('communities.')->group(function () {
        Route::get('/', [CommunityWebController::class, 'index'])->name('index');
        Route::get('create', [CommunityWebController::class, 'create'])->name('create');
        Route::post('/', [CommunityWebController::class, 'store'])->name('store');
        Route::get('{community}', [CommunityWebController::class, 'show'])->name('show');
        Route::delete('{community}', [CommunityWebController::class, 'destroy'])->name('destroy');
        Route::post('{community}/groups', [CommunityWebController::class, 'attachGroup'])->name('groups.attach');
        Route::delete('{community}/groups/{group}', [CommunityWebController::class, 'detachGroup'])->name('groups.detach');
        Route::post('{community}/announcements', [CommunityWebController::class, 'postAnnouncement'])->name('announcements.store');
    });

    Route::prefix('groups')->name('groups.')->group(function () {
        Route::get('/', [GroupsWebController::class, 'index'])->name('index');
        Route::get('create', [GroupsWebController::class, 'create'])->name('create');
        Route::post('/', [GroupsWebController::class, 'store'])->name('store');
        Route::get('{group}', [GroupsWebController::class, 'show'])->name('show');
        Route::delete('{group}', [GroupsWebController::class, 'destroy'])->name('destroy');
        Route::post('{group}/leave', [GroupsWebController::class, 'leave'])->name('leave');
        Route::patch('{group}/info', [GroupsWebController::class, 'updateInfo'])->name('info.update');

        Route::post('{group}/members', [GroupsWebController::class, 'addMembers'])->name('members.add');
        Route::delete('{group}/members/{userId}', [GroupsWebController::class, 'removeMember'])->name('members.remove');
        Route::post('{group}/transfer-ownership', [GroupsWebController::class, 'transferOwnership'])->name('transfer-ownership');
        Route::post('{group}/assign-role', [GroupsWebController::class, 'assignRole'])->name('assign-role');

        Route::patch('{group}/settings', [GroupsWebController::class, 'updateSettings'])->name('settings.update');
        Route::patch('{group}/permissions', [GroupsWebController::class, 'updatePermissions'])->name('permissions.update');
        Route::post('{group}/mute', [GroupsWebController::class, 'toggleMute'])->name('mute');
        Route::post('{group}/archive', [GroupsWebController::class, 'toggleArchive'])->name('archive');

        Route::post('{group}/invite', [GroupsWebController::class, 'generateInvite'])->name('invite.generate');
        Route::delete('{group}/invite', [GroupsWebController::class, 'revokeInvite'])->name('invite.revoke');

        Route::post('{group}/join-request', [GroupsWebController::class, 'submitJoinRequest'])->name('join-request.submit');
        Route::post('{group}/join-request/{joinRequest}/approve', [GroupsWebController::class, 'approveJoinRequest'])->name('join-request.approve');
        Route::post('{group}/join-request/{joinRequest}/reject', [GroupsWebController::class, 'rejectJoinRequest'])->name('join-request.reject');

        Route::post('{group}/announcements', [GroupContentWebController::class, 'storeAnnouncement'])->name('announcements.store');
        Route::delete('{group}/announcements/{announcement}', [GroupContentWebController::class, 'destroyAnnouncement'])->name('announcements.destroy');

        Route::post('{group}/polls', [GroupContentWebController::class, 'storePoll'])->name('polls.store');
        Route::post('{group}/polls/{poll}/vote', [GroupContentWebController::class, 'vote'])->name('polls.vote');
        Route::post('{group}/polls/{poll}/close', [GroupContentWebController::class, 'closePoll'])->name('polls.close');

        Route::post('{group}/events', [GroupContentWebController::class, 'storeEvent'])->name('events.store');
        Route::delete('{group}/events/{event}', [GroupContentWebController::class, 'destroyEvent'])->name('events.destroy');
    });
});
