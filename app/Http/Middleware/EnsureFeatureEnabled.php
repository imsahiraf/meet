<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Admin\Models\FeatureFlag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Meeting policies (owner 2026-11-13, large organisations): the admin's feature switches (Admin > Settings > Features) really
 * apply - a switched-off feature (guest mode, recording, screen share, whiteboard, breakout rooms, captions, AI summary) is refused
 * by the server, not only hidden in the screens.
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $key): Response
    {
        if (!FeatureFlag::enabled($key)) {
            $msg = __('Your organization has switched this feature off.');

            return $request->expectsJson() || $request->is('api/*')
                ? response()->json(['success' => false, 'message' => $msg, 'data' => null, 'errors' => ['feature' => [$key]]], 403)
                : abort(403, $msg);
        }

        return $next($request);
    }
}
