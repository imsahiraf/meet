<?php

namespace Modules\Authentication\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Modules\Authentication\Models\User;

/**
 * "Switch account without re-entering your password" - built entirely
 * on Laravel's own existing remember-me mechanism (users.remember_token
 * + Auth::loginUsingId()) rather than a new session/token layer: each
 * held account's entry here is only ever as trustworthy as that
 * account's own remember-me cookie already is, so this doesn't lower
 * the bar for any account below what "Remember me" already implies for
 * it individually. Only ever holds accounts that were logged into with
 * "Remember me" checked - there's no persistent token to switch back
 * with otherwise.
 *
 * Deliberately a single JSON cookie, not a database table - nothing
 * here is more sensitive than the recaller cookie Laravel's own
 * remember-me already writes, and it means "forget this device" is
 * just clearing a cookie, no cleanup query needed.
 */
class AccountSwitcherService
{
    public const COOKIE = 'meet_accounts';

    private const MINUTES = 60 * 24 * 365;

    /**
     * @return array<int, array{user_id: int, uuid: string, full_name: string, remember_token: string}>
     */
    private function read(Request $request): array
    {
        $raw = $request->cookie(self::COOKIE);

        if (! $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function write(array $accounts): void
    {
        Cookie::queue(self::COOKIE, json_encode(array_values($accounts)), self::MINUTES, '/', null, null, true, false, null);
    }

    /**
     * Called right after a "Remember me" login succeeds - adds/refreshes
     * this account's entry so it can be switched back to later without
     * the password.
     */
    public function remember(Request $request, User $user): void
    {
        if (! $user->getRememberToken()) {
            return;
        }

        $accounts = $this->read($request);
        $accounts = array_filter($accounts, fn ($a) => $a['user_id'] !== $user->id);
        $accounts[] = [
            'user_id' => $user->id,
            'uuid' => $user->uuid,
            'full_name' => $user->full_name,
            'remember_token' => $user->getRememberToken(),
        ];

        $this->write($accounts);
    }

    public function forget(Request $request, int $userId): void
    {
        $accounts = $this->read($request);
        $accounts = array_filter($accounts, fn ($a) => $a['user_id'] !== $userId);

        $this->write($accounts);
    }

    /**
     * "Logout of all accounts" - clears every held account from this
     * browser's switcher in one go, not just the one being logged out
     * of. Each account's own remember-me session/token elsewhere is
     * untouched (this only removes the local "Continue as..." tile,
     * the same as calling forget() for every entry at once).
     */
    public function forgetAll(Request $request): void
    {
        $this->write([]);
    }

    /**
     * @return array<int, array{user_id: int, uuid: string, full_name: string}>
     */
    public function list(Request $request, ?int $excludeUserId = null): array
    {
        return array_values(array_map(
            fn ($a) => ['user_id' => $a['user_id'], 'uuid' => $a['uuid'], 'full_name' => $a['full_name']],
            array_filter($this->read($request), fn ($a) => $a['user_id'] !== $excludeUserId),
        ));
    }

    /**
     * Validates the held entry's remember_token against that user's
     * CURRENT one (not just presence of an entry) - exactly the same
     * check Laravel's own SessionGuard::userFromRecaller() does for its
     * built-in recaller cookie, so revoking/rotating a user's
     * remember_token anywhere else (e.g. a real password change,
     * Force logout) invalidates this too, automatically.
     */
    public function resolve(Request $request, int $userId): ?User
    {
        $accounts = $this->read($request);
        $entry = collect($accounts)->firstWhere('user_id', $userId);

        if (! $entry || empty($entry['remember_token'])) {
            return null;
        }

        $user = User::find($userId);

        if (! $user || ! $user->getRememberToken() || ! hash_equals($user->getRememberToken(), $entry['remember_token'])) {
            return null;
        }

        return $user;
    }
}
