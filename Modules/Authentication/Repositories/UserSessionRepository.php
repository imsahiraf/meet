<?php

namespace Modules\Authentication\Repositories;

use Illuminate\Http\Request;
use Modules\Authentication\Models\User;
use Modules\Authentication\Models\UserSession;

class UserSessionRepository
{
    public function createFromRequest(User $user, Request $request, string $sessionId): UserSession
    {
        $agent = $this->parseUserAgent($request);

        return UserSession::create([
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'ip_address' => $request->ip(),
            'device_name' => $agent['device_name'],
            'browser' => $agent['browser'],
            'operating_system' => $agent['operating_system'],
            'platform' => $agent['platform'],
            'login_at' => now(),
            'last_activity_at' => now(),
            'status' => 'active',
        ]);
    }

    public function terminate(UserSession $session): UserSession
    {
        $session->forceFill([
            'status' => 'logged_out',
            'logout_at' => now(),
        ])->save();

        return $session;
    }

    public function terminateAllForUser(User $user): void
    {
        UserSession::where('user_id', $user->id)
            ->where('status', 'active')
            ->update(['status' => 'logged_out', 'logout_at' => now()]);
    }

    private function parseUserAgent(Request $request): array
    {
        $userAgent = (string) $request->userAgent();

        return [
            'device_name' => null,
            'browser' => $userAgent !== '' ? mb_substr($userAgent, 0, 100) : null,
            'operating_system' => null,
            'platform' => $request->header('Sec-Ch-Ua-Platform') ? trim((string) $request->header('Sec-Ch-Ua-Platform'), '"') : null,
        ];
    }
}
