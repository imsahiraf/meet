<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Http\Controllers\AdminEnvironmentController;
use Modules\Admin\Http\Controllers\AdminWebController;

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminWebController::class, 'dashboard'])->name('dashboard');
    Route::get('users', [AdminWebController::class, 'users'])->name('users');
    Route::get('roles', [AdminWebController::class, 'roles'])->name('roles');
    Route::get('settings', [AdminWebController::class, 'settings'])->name('settings');
    Route::get('logs', [AdminWebController::class, 'logs'])->name('logs');
    Route::get('archives', [AdminWebController::class, 'archives'])->name('archives');
    Route::get('environment', [AdminEnvironmentController::class, 'edit'])->name('environment');
    Route::post('environment/fields', [AdminEnvironmentController::class, 'updateFields'])->name('environment.fields');
    Route::post('environment/raw', [AdminEnvironmentController::class, 'updateRaw'])->name('environment.raw');
});
