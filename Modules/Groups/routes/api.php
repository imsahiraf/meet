<?php

use Illuminate\Support\Facades\Route;
use Modules\Groups\Http\Controllers\GroupAnnouncementController;
use Modules\Groups\Http\Controllers\GroupController;
use Modules\Groups\Http\Controllers\GroupEventController;
use Modules\Groups\Http\Controllers\GroupExportController;
use Modules\Groups\Http\Controllers\GroupInviteController;
use Modules\Groups\Http\Controllers\GroupJoinRequestController;
use Modules\Groups\Http\Controllers\GroupMediaController;
use Modules\Groups\Http\Controllers\GroupMemberController;
use Modules\Groups\Http\Controllers\GroupPermissionController;
use Modules\Groups\Http\Controllers\GroupPollController;
use Modules\Groups\Http\Controllers\GroupRoleController;
use Modules\Groups\Http\Controllers\GroupSettingsController;

Route::middleware(['auth:sanctum', 'throttle:60,1,groups'])->prefix('v1')->group(function () {
    // RAS-3 API16 - registered before {group} routes so "join" isn't
    // captured as a group-id wildcard.
    Route::post('groups/join', [GroupInviteController::class, 'join']);

    // RAS-3 API1-5 - group management
    Route::post('groups', [GroupController::class, 'store']);
    Route::get('groups', [GroupController::class, 'index']);
    Route::get('groups/{group}', [GroupController::class, 'show']);
    Route::put('groups/{group}', [GroupController::class, 'update']);
    Route::delete('groups/{group}', [GroupController::class, 'destroy']);

    // RAS-3 API15/17 - invites
    Route::post('groups/{group}/invite', [GroupInviteController::class, 'store']);
    Route::delete('groups/{group}/invite', [GroupInviteController::class, 'destroy']);

    // RAS-3 API18-20 - join requests
    Route::get('groups/{group}/join-requests', [GroupJoinRequestController::class, 'index']);
    Route::post('groups/{group}/join-request', [GroupJoinRequestController::class, 'store']);
    Route::post('groups/{group}/join-request/{joinRequest}/approve', [GroupJoinRequestController::class, 'approve']);
    Route::post('groups/{group}/join-request/{joinRequest}/reject', [GroupJoinRequestController::class, 'reject']);

    // RAS-3 API6-9, API39 - member management
    Route::get('groups/{group}/members', [GroupMemberController::class, 'index']);
    Route::post('groups/{group}/members', [GroupMemberController::class, 'store']);
    Route::delete('groups/{group}/members/{userId}', [GroupMemberController::class, 'destroy']);
    Route::post('groups/{group}/leave', [GroupMemberController::class, 'leave']);
    Route::post('groups/{group}/bulk-remove', [GroupMemberController::class, 'bulkRemove']);
    Route::post('groups/{group}/transfer-ownership', [GroupMemberController::class, 'transferOwnership']);

    // RAS-3 API10-12 - role management
    Route::post('groups/{group}/roles', [GroupRoleController::class, 'store']);
    Route::patch('groups/{group}/roles/{groupRole}', [GroupRoleController::class, 'update']);
    Route::delete('groups/{group}/roles/{groupRole}', [GroupRoleController::class, 'destroy']);

    // RAS-3 API13-14 - permissions
    Route::get('groups/{group}/permissions', [GroupPermissionController::class, 'index']);
    Route::patch('groups/{group}/permissions', [GroupPermissionController::class, 'update']);

    // RAS-3 API21-24 - announcements
    Route::get('groups/{group}/announcements', [GroupAnnouncementController::class, 'index']);
    Route::post('groups/{group}/announcements', [GroupAnnouncementController::class, 'store']);
    Route::patch('groups/{group}/announcements/{announcement}', [GroupAnnouncementController::class, 'update']);
    Route::delete('groups/{group}/announcements/{announcement}', [GroupAnnouncementController::class, 'destroy']);

    // RAS-3 API25-28 - polls
    Route::post('groups/{group}/polls', [GroupPollController::class, 'store']);
    Route::post('groups/{group}/polls/{poll}/vote', [GroupPollController::class, 'vote']);
    Route::get('groups/{group}/polls/{poll}', [GroupPollController::class, 'show']);
    Route::patch('groups/{group}/polls/{poll}/close', [GroupPollController::class, 'close']);

    // RAS-3 API29-32 - events
    Route::get('groups/{group}/events', [GroupEventController::class, 'index']);
    Route::post('groups/{group}/events', [GroupEventController::class, 'store']);
    Route::patch('groups/{group}/events/{event}', [GroupEventController::class, 'update']);
    Route::delete('groups/{group}/events/{event}', [GroupEventController::class, 'destroy']);

    // RAS-3 API33-35 - media
    Route::get('groups/{group}/media', [GroupMediaController::class, 'media']);
    Route::get('groups/{group}/links', [GroupMediaController::class, 'links']);
    Route::get('groups/{group}/documents', [GroupMediaController::class, 'documents']);

    // RAS-3 API36-38 - settings/mute/archive
    Route::patch('groups/{group}/settings', [GroupSettingsController::class, 'update']);
    Route::post('groups/{group}/mute', [GroupSettingsController::class, 'mute']);
    Route::post('groups/{group}/archive', [GroupSettingsController::class, 'archive']);

    // RAS-3 API40 - export (CSV; see GroupService::exportToCsv for why
    // not PDF/Excel)
    Route::get('groups/{group}/export', GroupExportController::class);
});
