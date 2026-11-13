<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Admin\Services\SettingsService;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-214 Maintenance Mode - implemented as a settings-driven flag
 * rather than Laravel's filesystem-based `artisan down`/`up`. That
 * mechanism writes a `storage/framework/down` file that affects the
 * whole process for every request (including the admin's own follow-
 * up requests) until explicitly lifted again - one crashed request
 * mid-toggle can leave the app stuck down with no easy way back in.
 * A DB-backed flag is instantly toggle-able, safe to test, and
 * naturally supports FR-214's "Allow Administrator Access" bypass.
 */
class CheckMaintenanceMode
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }

    // Always reachable so an as-yet-unauthenticated administrator can
    // still sign in during maintenance (FR-214 "Allow Administrator
    // Access") - authorization itself still applies as normal once
    // they're authenticated.
    private const EXEMPT_PATHS = [
        'login', 'register', 'verify-email', 'forgot-password', 'reset-password', 'resend-otp', 'logout',
        'api/v1/auth/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->settings->get('maintenance_mode_enabled', false)) {
            return $next($request);
        }

        if ($request->is(self::EXEMPT_PATHS)) {
            return $next($request);
        }

        if ($request->user()?->admin) {
            return $next($request);
        }

        if ($request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => $this->settings->get('maintenance_message', 'The application is undergoing maintenance.'),
                'data' => null,
                'errors' => null,
            ], 503);
        }

        return response()->view('errors.maintenance', [
            'message' => $this->settings->get('maintenance_message', 'The application is undergoing maintenance.'),
        ], 503);
    }
}
