<?php

namespace Modules\Authentication\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Authentication\Exceptions\InvalidTwoFactorChallengeException;
use Modules\Authentication\Exceptions\InvalidTwoFactorCodeException;
use Modules\Authentication\Models\TrustedDevice;
use Modules\Authentication\Models\TwoFactorChallenge;
use Modules\Authentication\Models\TwoFactorSecret;
use Modules\Authentication\Models\User;
use Modules\Authentication\Repositories\TrustedDeviceRepository;
use Modules\Authentication\Repositories\TwoFactorChallengeRepository;
use Modules\Authentication\Repositories\TwoFactorSecretRepository;
use PragmaRX\Google2FAQRCode\Google2FA;

class TwoFactorService
{
    private const RECOVERY_CODE_COUNT = 8;

    public const TRUSTED_DEVICE_COOKIE = 'meet_2fa_trusted_device';

    public function __construct(
        private readonly TwoFactorSecretRepository $secrets,
        private readonly TwoFactorChallengeRepository $challenges,
        private readonly TrustedDeviceRepository $trustedDevices,
        private readonly Google2FA $google2fa,
    ) {
    }

    public function isEnabledFor(User $user): bool
    {
        return $this->secrets->findConfirmedFor($user->id) !== null;
    }

    /**
     * A raw cookie token is hashed the same way everywhere it's looked
     * up (create + check), so the raw secret never has to round-trip
     * through the database - only its hash is ever stored or compared,
     * matching how this app already treats other bearer-style secrets.
     */
    private function hashDeviceToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public function isTrustedDevice(User $user, ?string $rawToken): bool
    {
        if (! $rawToken) {
            return false;
        }

        $device = $this->trustedDevices->findActiveByIdentifier($user, $this->hashDeviceToken($rawToken));

        if (! $device) {
            return false;
        }

        $this->trustedDevices->touch($device);

        return true;
    }

    /**
     * @return string the raw token - the caller sets this as a cookie
     *                 (never persisted itself, only its hash is).
     */
    public function trustDevice(User $user, Request $request): string
    {
        $rawToken = Str::random(64);
        $this->trustedDevices->create($user, $this->hashDeviceToken($rawToken), $request);

        return $rawToken;
    }

    public function trustedDevicesFor(User $user): \Illuminate\Support\Collection
    {
        return $user->trustedDevices()->where('status', 'active')->latest('last_used_at')->get();
    }

    public function revokeTrustedDevice(User $user, TrustedDevice $device): void
    {
        if ($device->user_id !== $user->id) {
            throw new AuthorizationException('You may only revoke your own trusted devices.');
        }

        $this->trustedDevices->revoke($device);
    }

    /**
     * Starts (or restarts) setup - never active until confirm() proves
     * the user actually scanned it right with a real code.
     */
    public function startSetup(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey();
        $this->secrets->startSetup($user, $secret);

        return [
            'secret' => $secret,
            'qr_code' => $this->google2fa->getQRCodeInline(config('app.name'), $user->email, $secret),
        ];
    }

    /**
     * @return string[] the plain recovery codes - shown to the user
     *                   exactly once, never retrievable again afterward.
     */
    public function confirm(User $user, string $code): array
    {
        $secret = $this->secrets->findFor($user);

        if (! $secret || $secret->isConfirmed() || ! $this->google2fa->verifyKey($secret->secret, $code)) {
            throw new InvalidTwoFactorCodeException;
        }

        $recoveryCodes = $this->generateRecoveryCodes();
        $this->secrets->confirm($secret, $recoveryCodes);

        return $recoveryCodes;
    }

    public function disable(User $user): void
    {
        $this->secrets->delete($user);
    }

    public function regenerateRecoveryCodes(User $user): array
    {
        $secret = $this->secrets->findConfirmedFor($user->id);

        if (! $secret) {
            throw new InvalidTwoFactorCodeException('Two-factor authentication is not enabled.');
        }

        $recoveryCodes = $this->generateRecoveryCodes();
        $this->secrets->replaceRecoveryCodes($secret, $recoveryCodes);

        return $recoveryCodes;
    }

    public function createChallenge(User $user, bool $remember): TwoFactorChallenge
    {
        return $this->challenges->create($user, $remember);
    }

    /**
     * Accepts either a live TOTP code or a one-time recovery code.
     * Returns the challenge (carrying both the user and the original
     * "remember me" choice) so the caller can finish issuing a
     * session/token exactly as if 2FA had never been involved.
     */
    public function verifyChallenge(string $challengeUuid, string $code): TwoFactorChallenge
    {
        $challenge = $this->challenges->findActiveByUuid($challengeUuid);

        if (! $challenge) {
            throw new InvalidTwoFactorChallengeException;
        }

        $secret = $this->secrets->findConfirmedFor($challenge->user_id);

        if (! $secret) {
            throw new InvalidTwoFactorChallengeException;
        }

        if (! $this->verifyCodeOrRecovery($secret, $code)) {
            throw new InvalidTwoFactorCodeException;
        }

        return $this->challenges->markVerified($challenge);
    }

    private function verifyCodeOrRecovery(TwoFactorSecret $secret, string $code): bool
    {
        if ($this->google2fa->verifyKey($secret->secret, $code)) {
            return true;
        }

        $recoveryCodes = $secret->recovery_codes ?? [];

        if (in_array($code, $recoveryCodes, true)) {
            $this->secrets->consumeRecoveryCode($secret, $code);

            return true;
        }

        return false;
    }

    private function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn () => Str::upper(Str::random(4)).'-'.Str::upper(Str::random(4)))
            ->all();
    }
}
