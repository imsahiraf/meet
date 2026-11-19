<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Authentication\Exceptions\AccountNotVerifiedException;
use Modules\Authentication\Exceptions\AccountSuspendedException;
use Modules\Authentication\Models\User;
use Modules\Authentication\Services\AuthenticationService;

/**
 * Entry from Insights by Meet (its "Open Meet" link): /sso/insights?token=...&next=/path
 * Token contract (Insights docs/platform/09-integrations.md): base64url(payload) "." base64url(HMAC-SHA256(base64url(payload), secret)),
 * payload {iss:"insights", aud, sub:<email>, iat, exp, jti}. Only an EXISTING, verified, active Meet account with that
 * e-mail is signed in - nothing is created, no Meet data goes back to Insights, and Meet's own 2FA still applies.
 */
class InsightsSsoController extends Controller
{
    public function __construct(private readonly AuthenticationService $auth)
    {
    }

    public function enter(Request $request): RedirectResponse
    {
        $secret = (string) config('services.insights.sso_secret');
        $payload = $secret !== '' ? self::verify((string) $request->query('token'), (string) config('services.insights.audience', 'meet'), $secret) : null;
        if (! $payload || ($payload['iss'] ?? '') !== 'insights' || empty($payload['jti']) || empty($payload['sub'])
            || ! Cache::add('insights-sso-jti:' . $payload['jti'], 1, now()->addMinutes(15))) { // every token works once
            Log::warning('Insights SSO rejected', ['ip' => $request->ip()]);

            return redirect()->route('login')->withErrors(['login' => 'This sign-in link is invalid or expired. Open Meet from Insights by Meet again, or sign in here.']);
        }
        $next = (string) $request->query('next', '/');
        $next = str_starts_with($next, '/') && ! str_starts_with($next, '//') && ! str_contains($next, '\\') ? $next : '/';

        $sub = strtolower((string) $payload['sub']);

        // Linking: someone signed in to their own Meet account joins it to this Insights login (any e-mail).
        if (! empty($payload['link']) && Auth::check()) {
            $taken = DB::table('insights_links')->where('insights_email', $sub)->where('user_id', '!=', Auth::id())->exists()
                || User::where('email', $sub)->where('id', '!=', Auth::id())->exists();
            if ($taken) {
                return redirect('/settings/insights')->withErrors(['link' => 'This Insights login already belongs to another Meet account.']);
            }
            DB::table('insights_links')->updateOrInsert(['insights_email' => $sub], ['user_id' => Auth::id(), 'org' => $payload['org'] ?? null, 'updated_at' => now(), 'created_at' => now()]);
            \App\Support\UserBrand::remember($sub, $payload['brand'] ?? null);
            self::applyMailPlan(Auth::id(), $payload);

            return redirect('/settings/insights')->with('status', 'Your Meet account is now linked to ' . $sub . '. Opening Meet from Insights signs you in here.');
        }

        // Entry never creates an account (owner rule 2026-10-29): Insights creates and links the Meet account when the user is made
        // (POST /api/insights/provision); someone without one gets this message.
        $user = self::userFor(array_diff_key($payload, ['provision' => 1]));
        if (! $user) {
            return redirect()->route('login')->withErrors(['login' => 'You do not have a Meet account linked to your Insights account (' . $payload['sub'] . '). Ask your Insights admin to create it, or register / sign in here with your own Meet account and link it under Settings > Insights.']);
        }
        self::applyMailPlan($user->id, $payload);
        \App\Support\UserBrand::remember(strtolower((string) $payload['sub']), $payload['brand'] ?? null); // the reseller brand of today
        if (Auth::check()) {
            if (Auth::id() === $user->id) {
                return redirect($next);
            }
            $this->auth->logoutWeb($request);
        }
        try {
            $this->auth->authenticateViaSso($request, $user);
        } catch (AccountSuspendedException|AccountNotVerifiedException $e) {
            return redirect()->route('login')->withErrors(['login' => $e->getMessage() ?: 'This account cannot sign in.']);
        }
        $request->session()->put('url.intended', url($next));
        $result = $this->auth->loginForWebViaSso($request, $user);
        if ($result['requires_2fa']) {
            return redirect()->route('two-factor.challenge.show', ['challenge' => $result['challenge_token']]);
        }

        return redirect()->intended(url($next));
    }

    /**
     * Server-to-server: Insights asks Meet to create and link the account of one Insights user (POST /api/insights/provision, form field
     * "token" = the signed entry token with the claim provision). Used right after the Insights user is created. Safe to repeat.
     */
    public function provision(Request $request): \Illuminate\Http\JsonResponse
    {
        $secret = (string) config('services.insights.sso_secret');
        $payload = $secret !== '' ? self::verify((string) $request->input('token'), (string) config('services.insights.audience', 'meet'), $secret) : null;
        if (! $payload || ($payload['iss'] ?? '') !== 'insights' || empty($payload['jti']) || empty($payload['sub']) || empty($payload['provision'])
            || ! Cache::add('insights-sso-jti:' . $payload['jti'], 1, now()->addMinutes(15))) {
            return response()->json(['ok' => false, 'message' => 'invalid or expired request'], 401);
        }
        $sub = strtolower((string) $payload['sub']);
        $existed = User::where('email', $sub)->exists() || DB::table('insights_links')->where('insights_email', $sub)->exists();
        $user = self::userFor($payload);
        if (! $user) {
            return response()->json(['ok' => false, 'message' => 'could not create the account'], 422);
        }
        DB::table('insights_links')->updateOrInsert(['insights_email' => $sub], ['user_id' => $user->id, 'org' => $payload['org'] ?? null, 'updated_at' => now(), 'created_at' => now()]);
        \App\Support\UserBrand::remember($sub, $payload['brand'] ?? null);
        self::applyMailPlan($user->id, $payload);

        return response()->json(['ok' => true, 'created' => ! $existed]);
    }

    /**
     * The Meet account of an Insights login: the linked account, else the one with the same e-mail; with the "provision"
     * claim a new account is made (the e-mail was verified by Insights). Used by the sign-in link and the mail API.
     */
    public static function userFor(array $payload): ?User
    {
        $sub = strtolower((string) ($payload['sub'] ?? ''));
        if ($sub === '') {
            return null;
        }
        $linked = DB::table('insights_links')->where('insights_email', $sub)->value('user_id');
        $user = $linked ? User::find($linked) : User::where('email', $sub)->first();
        if (! $user && ! empty($payload['provision'])) {
            $user = new User();
            $user->forceFill(['full_name' => mb_substr((string) ($payload['name'] ?? strstr($sub, '@', true)), 0, 150), 'email' => $sub,
                'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(40)), 'status' => 'active', 'email_verified_at' => now()])->save();
        }

        return $user;
    }

    /** Mailbox size from the person's Insights package (claim mail_mb); without it the free size applies. */
    public static function applyMailPlan(int $userId, array $payload): void
    {
        if (! class_exists(\Modules\Mailbox\Models\MailSetting::class) || ! \Illuminate\Support\Facades\Schema::hasTable('mail_settings')) {
            return;
        }
        $s = \Modules\Mailbox\Models\MailSetting::for($userId);
        $mb = (int) ($payload['mail_mb'] ?? 0);
        $s->forceFill(['quota_mb' => $mb > 0 ? $mb : null, 'plan' => $mb > 0 ? mb_substr('Insights: ' . ($payload['plan'] ?? $payload['org'] ?? ''), 0, 60) : null])->save();
    }

    /** @return array<string, mixed>|null */
    public static function verify(string $token, string $audience, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $parts[0], $secret, true)), '+/', '-_'), '=');
        if (! hash_equals($expected, $parts[1])) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true);
        if (! is_array($payload) || ($payload['aud'] ?? null) !== $audience || (int) ($payload['exp'] ?? 0) < time() || (int) ($payload['iat'] ?? 0) > time() + 60) {
            return null;
        }

        return $payload;
    }
}
