<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Free Meet tools (MeetWord, MeetGrid ... served by Meet Insights) ask visitors to sign up / log in with Meet.
 * /tools/pass?return=<tool page> (signed-in users only; sign-up and login come back here) sends the visitor back with
 * ?zpass=<token>: base64url(json {uid, name, email, exp}) "." hex HMAC-SHA256(body, TOOLS_PASS_SECRET), valid 12 hours.
 * Only addresses on the company domains are accepted as return targets; no Meet data other than name and e-mail leaves.
 */
class ToolsPassController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $return = (string) $request->query('return', '');
        $parts = parse_url($return);
        $host = strtolower($parts['host'] ?? '');
        $hosts = array_filter(array_map('trim', explode(',', (string) config('services.tools.hosts'))));
        $allowed = in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            && collect($hosts)->contains(fn ($h) => $host === $h || str_ends_with($host, '.'.$h));
        $secret = (string) config('services.tools.pass_secret');
        abort_unless($allowed && $secret !== '', 404);

        $user = $request->user();
        $body = rtrim(strtr(base64_encode(json_encode([
            'uid' => $user->id, 'name' => (string) $user->full_name, 'email' => (string) $user->email, 'exp' => time() + 12 * 3600,
        ])), '+/', '-_'), '=');
        $token = $body.'.'.hash_hmac('sha256', $body, $secret);

        return redirect()->away($return.(str_contains($return, '?') ? '&' : '?').'zpass='.$token);
    }
}
