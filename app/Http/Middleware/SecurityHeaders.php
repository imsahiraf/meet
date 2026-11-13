<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SRS/16.md FR-272 names CSP explicitly; HSTS/X-Frame-Options/
 * X-Content-Type-Options/Referrer-Policy are never itemized anywhere
 * in the documentation corpus (a confirmed Phase 8 research gap) -
 * these are the standard OWASP-recommended values for each, applied
 * app-wide since nothing in the docs scopes them more narrowly.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // connect-src allows Firebase Cloud Messaging's two network
        // dependencies (Installations API for its anonymous client ID,
        // FCM registration for the actual device token) - both are
        // real requests the Firebase Web SDK makes itself when
        // obtaining a push token (resources/js/push-notifications.js),
        // not requests this app's own code initiates.
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self' https://firebaseinstallations.googleapis.com https://fcmregistrations.googleapis.com; frame-ancestors 'self';",
        );

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
