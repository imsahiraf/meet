<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates every web page behind the forced-password-change flow once an
 * admin marks a reset temporary (see
 * Modules\Admin\Services\UserManagementService::resetPassword()) -
 * catches direct URL navigation/back-button, not just the initial
 * post-login redirect.
 */
class EnsurePasswordIsNotTemporary
{
    private const EXEMPT_PATHS = [
        'force-password-change', 'logout', 'api/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        if ($request->is(self::EXEMPT_PATHS)) {
            return $next($request);
        }

        return redirect()->route('force-password-change.show');
    }
}
