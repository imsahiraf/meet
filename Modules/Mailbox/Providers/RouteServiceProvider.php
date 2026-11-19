<?php

namespace Modules\Mailbox\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public function map(): void
    {
        Route::middleware('web')->group(module_path('Mailbox', '/routes/web.php'));
        Route::middleware('api')->group(module_path('Mailbox', '/routes/api.php'));
        // phone mail apps (Exchange ActiveSync, ported from Insights by Meet 2026-11-10): no web session, no CSRF - Basic auth with an app password
        Route::middleware(['throttle:300,1'])->group(function () {
            $c = \Modules\Mailbox\Http\Controllers\ActiveSyncController::class;
            Route::match(['OPTIONS'], 'Microsoft-Server-ActiveSync', [$c, 'options']);
            Route::post('Microsoft-Server-ActiveSync', [$c, 'handle'])->name('mailbox.activesync');
            foreach (['autodiscover/autodiscover.xml', 'Autodiscover/Autodiscover.xml'] as $u) {
                Route::match(['GET', 'POST'], $u, [$c, 'autodiscover']);
            }
        });
    }
}
