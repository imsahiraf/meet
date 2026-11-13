<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\DashboardController;

Route::middleware('auth')->get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
