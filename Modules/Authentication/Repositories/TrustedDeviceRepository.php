<?php

namespace Modules\Authentication\Repositories;

use Illuminate\Http\Request;
use Modules\Authentication\Models\TrustedDevice;
use Modules\Authentication\Models\User;

class TrustedDeviceRepository
{
    private const TRUST_DAYS = 30;

    /**
     * $hashedIdentifier is a SHA-256 hash of the random token that
     * actually lives in the browser's cookie - only the hash is ever
     * stored, mirroring how password reset/email-verification tokens
     * elsewhere in this app are handled (the raw secret never touches
     * the database).
     */
    public function create(User $user, string $hashedIdentifier, Request $request): TrustedDevice
    {
        $agent = $this->parseUserAgent($request);

        return TrustedDevice::create([
            'user_id' => $user->id,
            'device_identifier' => $hashedIdentifier,
            'browser' => $agent['browser'],
            'operating_system' => $agent['operating_system'],
            'platform' => $agent['platform'],
            'last_used_at' => now(),
            'trusted_at' => now(),
            'expires_at' => now()->addDays(self::TRUST_DAYS),
            'status' => 'active',
        ]);
    }

    public function findActiveByIdentifier(User $user, string $hashedIdentifier): ?TrustedDevice
    {
        return TrustedDevice::where('user_id', $user->id)
            ->where('device_identifier', $hashedIdentifier)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    public function touch(TrustedDevice $device): void
    {
        $device->forceFill(['last_used_at' => now()])->save();
    }

    public function revoke(TrustedDevice $device): void
    {
        $device->forceFill(['status' => 'revoked'])->save();
    }

    /**
     * Same low-effort convention UserSessionRepository::parseUserAgent()
     * already uses elsewhere in this app - a raw truncated User-Agent
     * string, no real parsing library, device_name left unset since
     * nothing populates that column anywhere in the codebase either.
     */
    private function parseUserAgent(Request $request): array
    {
        $userAgent = (string) $request->userAgent();

        return [
            'browser' => $userAgent !== '' ? mb_substr($userAgent, 0, 100) : null,
            'operating_system' => null,
            'platform' => $request->header('Sec-Ch-Ua-Platform') ? trim((string) $request->header('Sec-Ch-Ua-Platform'), '"') : null,
        ];
    }
}
