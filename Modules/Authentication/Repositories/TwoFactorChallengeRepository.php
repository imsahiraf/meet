<?php

namespace Modules\Authentication\Repositories;

use Modules\Authentication\Models\TwoFactorChallenge;
use Modules\Authentication\Models\User;

class TwoFactorChallengeRepository
{
    private const EXPIRES_IN_MINUTES = 10;

    public function create(User $user, bool $remember): TwoFactorChallenge
    {
        return TwoFactorChallenge::create([
            'user_id' => $user->id,
            'remember' => $remember,
            'expires_at' => now()->addMinutes(self::EXPIRES_IN_MINUTES),
        ]);
    }

    public function findActiveByUuid(string $uuid): ?TwoFactorChallenge
    {
        return TwoFactorChallenge::where('uuid', $uuid)
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    public function markVerified(TwoFactorChallenge $challenge): TwoFactorChallenge
    {
        $challenge->forceFill(['verified_at' => now()])->save();

        return $challenge;
    }
}
