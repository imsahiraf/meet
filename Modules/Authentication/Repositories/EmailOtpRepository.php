<?php

namespace Modules\Authentication\Repositories;

use Modules\Authentication\Models\EmailOtp;
use Modules\Authentication\Models\User;

class EmailOtpRepository
{
    /**
     * Invalidate any previous pending OTP for the same email/purpose,
     * then generate and persist a new one (FR-002, FR-003).
     */
    public function generate(string $email, string $purpose, ?User $user = null): EmailOtp
    {
        $config = config('authentication.otp');

        $this->invalidatePending($email, $purpose);

        return EmailOtp::create([
            'user_id' => $user?->id,
            'email' => mb_strtolower($email),
            'otp' => str_pad((string) random_int(0, 10 ** $config['length'] - 1), $config['length'], '0', STR_PAD_LEFT),
            'purpose' => $purpose,
            'max_attempts' => $config['max_verify_attempts'],
            'expires_at' => now()->addMinutes($config['expires_in_minutes']),
            'status' => 'pending',
        ]);
    }

    public function invalidatePending(string $email, string $purpose): void
    {
        EmailOtp::where('email', mb_strtolower($email))
            ->where('purpose', $purpose)
            ->where('status', 'pending')
            ->update(['status' => 'expired']);
    }

    public function findPending(string $email, string $purpose): ?EmailOtp
    {
        return EmailOtp::where('email', mb_strtolower($email))
            ->where('purpose', $purpose)
            ->where('status', 'pending')
            ->latest('id')
            ->first();
    }

    public function countResendsToday(string $email, string $purpose): int
    {
        return EmailOtp::where('email', mb_strtolower($email))
            ->where('purpose', $purpose)
            ->whereDate('created_at', now()->toDateString())
            ->count();
    }

    public function markVerified(EmailOtp $otp): EmailOtp
    {
        $otp->forceFill([
            'status' => 'verified',
            'verified_at' => now(),
        ])->save();

        return $otp;
    }

    public function incrementAttempts(EmailOtp $otp): EmailOtp
    {
        $otp->increment('attempts');

        if ($otp->attempts >= $otp->max_attempts) {
            $otp->forceFill(['status' => 'blocked'])->save();
        }

        return $otp;
    }
}
