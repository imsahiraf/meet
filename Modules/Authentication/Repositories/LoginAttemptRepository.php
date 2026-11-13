<?php

namespace Modules\Authentication\Repositories;

use Illuminate\Http\Request;
use Modules\Authentication\Models\LoginAttempt;
use Modules\Authentication\Models\User;

class LoginAttemptRepository
{
    public function record(
        Request $request,
        string $login,
        string $result,
        ?User $user = null,
        ?string $failureReason = null,
    ): LoginAttempt {
        return LoginAttempt::create([
            'user_id' => $user?->id,
            'email' => str_contains($login, '@') ? mb_strtolower($login) : null,
            'phone' => str_contains($login, '@') ? null : $login,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'login_result' => $result,
            'failure_reason' => $failureReason,
            'attempted_at' => now(),
        ]);
    }
}
