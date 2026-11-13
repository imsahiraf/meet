<?php

use App\Http\Middleware\CheckMaintenanceMode;
use App\Http\Middleware\EnsurePasswordIsNotTemporary;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trusted proxies were never configured at all, on a stack
        // (cPanel's own web server in front of PHP-FPM, plus whatever
        // CDN/proxy may sit in front of that) where Laravel otherwise
        // has no way to know a request actually arrived over HTTPS -
        // it only ever sees the plain-HTTP hop to PHP-FPM itself. That
        // silently broke $request->secure()/scheme detection in
        // production (confirmed by HSTS never being set - see
        // SecurityHeaders - and is the real root cause behind Google
        // Sign-In throwing InvalidStateException there: the OAuth
        // callback's session cookie is Secure-flagged from an https
        // origin, but the request Laravel *thinks* it received looks
        // like plain http, so the cookie never round-trips and the
        // callback starts a brand-new empty session with no state to
        // compare against. `at: '*'` (trust the immediate upstream
        // hop's X-Forwarded-* headers regardless of its IP) is the
        // standard choice here since the exact proxy IP isn't known
        // and can change (CDN, cPanel updates) - safe specifically
        // because PHP-FPM itself is never directly reachable from the
        // public internet on this hosting, only through that hop.
        $middleware->trustProxies(at: '*');

        // Web (Blade) requests redirect unauthenticated users to /login;
        // API requests get null so they fall through to the JSON 401
        // handler below instead of attempting a redirect.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('login'));

        // Laravel's default RedirectIfAuthenticated middleware looks for
        // a route literally *named* "dashboard" first (regardless of
        // what "/" itself resolves to) and sends an already-logged-in
        // user there when they visit e.g. /login - this app has such a
        // route, so without overriding it here that default would keep
        // landing people on Dashboard even after Chat became the actual
        // landing page everywhere else (routes/web.php's "home" route,
        // post-login, post-verification).
        $middleware->redirectUsersTo(fn () => route('chat.index'));

        // Lets the Blade UI's JavaScript call our own /api/v1/* JSON
        // endpoints using the browser's session cookie (Sanctum SPA
        // mode) instead of a bearer token - reusing the same, already
        // tested API controllers instead of duplicating them for the web.
        $middleware->statefulApi();

        $middleware->alias(['admin' => EnsureUserIsAdmin::class, 'feature' => \App\Http\Middleware\EnsureFeatureEnabled::class]);

        $middleware->append(SecurityHeaders::class);

        // Must run AFTER session/auth middleware (StartSession for web,
        // Sanctum's stateful-API middleware for api) so request()->user()
        // is actually resolvable by the time it checks for an admin
        // bypass. As a *global* middleware (the old $middleware->append()
        // call below) it ran before either of those in the pipeline, so
        // user() was always null here - the admin bypass never actually
        // worked for any admin, ever (confirmed via a live report: a
        // Super Admin still got a 503 on every dashboard/action while
        // maintenance mode was on).
        $middleware->web(append: [CheckMaintenanceMode::class, EnsurePasswordIsNotTemporary::class]);
        $middleware->api(append: [CheckMaintenanceMode::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API requests always get the RAS-1 §3/§4 standard JSON envelope.
        // Web (Blade) requests fall through to Laravel's normal error
        // handling, which renders our resources/views/errors/*.blade.php.
        $isApi = fn (Request $request) => $request->is('api/*');

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
                'data' => null,
                'errors' => null,
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'This action is unauthorized.',
                'data' => null,
                'errors' => null,
            ], 403);
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Resource not found.',
                'data' => null,
                'errors' => null,
            ], 404);
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'data' => null,
                'errors' => $e->errors(),
            ], 422);
        });
    })->create();
