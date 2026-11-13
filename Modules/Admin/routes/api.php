<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Http\Controllers\AdminArchiveController;
use Modules\Admin\Http\Controllers\AdminDashboardController;
use Modules\Admin\Http\Controllers\AdminLogController;
use Modules\Admin\Http\Controllers\AdminRoleController;
use Modules\Admin\Http\Controllers\AdminSettingsController;
use Modules\Admin\Http\Controllers\AdminUserController;

Route::middleware(['auth:sanctum', 'admin', 'throttle:300,1,admin'])->prefix('v1/admin')->group(function () {
    Route::get('dashboard', [AdminDashboardController::class, 'dashboard']);
    Route::get('analytics/users', [AdminDashboardController::class, 'userAnalytics']);
    Route::get('analytics/chat', [AdminDashboardController::class, 'chatAnalytics']);
    Route::get('analytics/meetings', [AdminDashboardController::class, 'meetingAnalytics']);
    Route::get('analytics/storage', [AdminDashboardController::class, 'storageAnalytics']);
    Route::get('reports/export', [AdminDashboardController::class, 'export']);

    Route::get('users', [AdminUserController::class, 'index']);
    Route::get('users/{user}', [AdminUserController::class, 'show']);
    Route::post('users/{user}/suspend', [AdminUserController::class, 'suspend']);
    Route::post('users/{user}/activate', [AdminUserController::class, 'activate']);
    Route::delete('users/{user}', [AdminUserController::class, 'destroy']);
    Route::post('users/{id}/restore', [AdminUserController::class, 'restore']);
    Route::post('users/{user}/force-logout', [AdminUserController::class, 'forceLogout']);
    Route::post('users/{user}/reset-password', [AdminUserController::class, 'resetPassword']);
    Route::post('users/{user}/one-view-super-access', [AdminUserController::class, 'grantOneViewSuperAccess']);
    Route::delete('users/{user}/one-view-super-access', [AdminUserController::class, 'revokeOneViewSuperAccess']);
    Route::post('users/{user}/roles/{role}', [AdminRoleController::class, 'assign']);
    Route::delete('users/{user}/roles/{role}', [AdminRoleController::class, 'revoke']);

    Route::get('roles', [AdminRoleController::class, 'index']);
    Route::post('roles', [AdminRoleController::class, 'store']);
    Route::patch('roles/{role}', [AdminRoleController::class, 'update']);
    Route::delete('roles/{role}', [AdminRoleController::class, 'destroy']);
    Route::post('roles/{role}/clone', [AdminRoleController::class, 'clone']);
    Route::get('permissions', [AdminRoleController::class, 'permissions']);

    Route::get('settings/general', [AdminSettingsController::class, 'general']);
    Route::patch('settings/general', [AdminSettingsController::class, 'updateGeneral']);
    Route::get('settings/branding', [AdminSettingsController::class, 'branding']);
    Route::patch('settings/branding', [AdminSettingsController::class, 'updateBranding']);
    Route::get('settings/smtp', [AdminSettingsController::class, 'smtp']);
    Route::patch('settings/smtp', [AdminSettingsController::class, 'updateSmtp']);
    Route::post('settings/smtp/test', [AdminSettingsController::class, 'testSmtp']);
    Route::get('settings/features', [AdminSettingsController::class, 'featureFlags']);
    Route::patch('settings/features/{key}', [AdminSettingsController::class, 'updateFeatureFlag']);
    Route::post('system/maintenance', [AdminSettingsController::class, 'setMaintenanceMode']);

    Route::get('logs/audit', [AdminLogController::class, 'audit']);
    Route::get('logs/audit/{auditLog}', [AdminLogController::class, 'show']);
    Route::get('logs/activity', [AdminLogController::class, 'activity']);

    Route::get('archives', [AdminArchiveController::class, 'index']);
    Route::post('archives', [AdminArchiveController::class, 'store']);
    Route::get('archives/settings', [AdminArchiveController::class, 'settings']);
    Route::patch('archives/settings', [AdminArchiveController::class, 'updateSettings']);
    Route::get('archives/{archive}', [AdminArchiveController::class, 'show']);
    Route::get('archives/{archive}/download', [AdminArchiveController::class, 'download']);
});
