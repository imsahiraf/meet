<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-219 - every Admin Panel route requires an `admins` row (Super
 * Admins bypass Gate checks via AppServiceProvider::boot(), but a
 * plain Admin still needs at least one granted permission to get
 * past this gate at all - the per-action Gate::authorize() calls in
 * AdminController handle which specific permission is required).
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->admin) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'This action requires administrator access.',
                    'data' => null,
                    'errors' => null,
                ], 403);
            }

            abort(403, 'This action requires administrator access.');
        }

        return $next($request);
    }
}
