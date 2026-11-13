<?php

namespace Modules\Authentication\Repositories;

use Modules\Authentication\Models\TwoFactorSecret;
use Modules\Authentication\Models\User;

class TwoFactorSecretRepository
{
    public function findFor(User $user): ?TwoFactorSecret
    {
        return TwoFactorSecret::where('user_id', $user->id)->first();
    }

    public function findConfirmedFor(int $userId): ?TwoFactorSecret
    {
        return TwoFactorSecret::where('user_id', $userId)->whereNotNull('confirmed_at')->first();
    }

    /**
     * Replaces any prior (even confirmed) secret - re-enabling always
     * starts a fresh setup, never silently reactivates an old one.
     */
    public function startSetup(User $user, string $secret): TwoFactorSecret
    {
        TwoFactorSecret::where('user_id', $user->id)->delete();

        return TwoFactorSecret::create([
            'user_id' => $user->id,
            'secret' => $secret,
            'confirmed_at' => null,
        ]);
    }

    public function confirm(TwoFactorSecret $secret, array $recoveryCodes): TwoFactorSecret
    {
        $secret->forceFill(['confirmed_at' => now(), 'recovery_codes' => $recoveryCodes])->save();

        return $secret;
    }

    public function replaceRecoveryCodes(TwoFactorSecret $secret, array $recoveryCodes): TwoFactorSecret
    {
        $secret->forceFill(['recovery_codes' => $recoveryCodes])->save();

        return $secret;
    }

    public function consumeRecoveryCode(TwoFactorSecret $secret, string $code): void
    {
        $secret->forceFill([
            'recovery_codes' => array_values(array_diff($secret->recovery_codes ?? [], [$code])),
        ])->save();
    }

    public function delete(User $user): void
    {
        TwoFactorSecret::where('user_id', $user->id)->delete();
    }
}
