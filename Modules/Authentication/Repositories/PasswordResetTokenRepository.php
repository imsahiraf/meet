<?php

namespace Modules\Authentication\Repositories;

use Illuminate\Support\Str;
use Modules\Authentication\Models\PasswordResetToken;
use Modules\Authentication\Models\User;

class PasswordResetTokenRepository
{
    /**
     * Invalidate any existing active token, then issue a new one (one
     * active reset token per user, per DDS-1 business rules).
     */
    public function generate(User $user): PasswordResetToken
    {
        $expiresInMinutes = config('authentication.password_reset.token_expires_in_minutes');

        PasswordResetToken::where('user_id', $user->id)
            ->where('status', 'active')
            ->update(['status' => 'expired']);

        return PasswordResetToken::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'token' => Str::random(64),
            'expires_at' => now()->addMinutes($expiresInMinutes),
            'status' => 'active',
        ]);
    }

    public function findActiveByToken(string $token): ?PasswordResetToken
    {
        return PasswordResetToken::where('token', $token)
            ->where('status', 'active')
            ->first();
    }

    public function markUsed(PasswordResetToken $resetToken): PasswordResetToken
    {
        $resetToken->forceFill([
            'status' => 'used',
            'used_at' => now(),
        ])->save();

        return $resetToken;
    }
}
