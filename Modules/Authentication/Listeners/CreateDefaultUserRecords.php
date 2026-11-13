<?php

namespace Modules\Authentication\Listeners;

use Modules\Admin\Models\Role;
use Modules\Authentication\Events\UserRegistered;
use Modules\User\Models\UserPreference;
use Modules\User\Models\UserPresence;
use Modules\User\Models\UserPrivacy;
use Modules\User\Models\UserProfile;

/**
 * Deliberately NOT queued: these rows (profile/preferences/privacy/
 * presence, default role) are load-bearing for basically every other
 * page the user hits right after registering - queuing this made it
 * silently depend on a queue worker being alive the same way the OTP
 * email did (see RegistrationOtpNotification's docblock), and it's
 * cheap enough (plain local DB writes, no external I/O) that there's
 * no real benefit to deferring it anyway.
 */
class CreateDefaultUserRecords
{
    /**
     * Idempotent by design: UserRegistered is also re-dispatched by
     * AuthenticationService::resendOtp(), so this must tolerate running
     * more than once for the same user without violating the unique
     * user_id constraint on these tables.
     */
    public function handle(UserRegistered $event): void
    {
        $user = $event->user;

        // Registration only ever collects a single full_name field, but
        // the Settings page has separate first_name/last_name inputs -
        // without this, every real user's Settings page shows those
        // boxes blank right after signup, looking exactly like nothing
        // saved even though they already gave the app their name.
        [$firstName, $lastName] = $this->splitFullName($user->full_name);

        UserProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['first_name' => $firstName, 'last_name' => $lastName],
        );
        UserPreference::firstOrCreate(['user_id' => $user->id]);
        UserPrivacy::firstOrCreate(['user_id' => $user->id]);
        UserPresence::firstOrCreate(['user_id' => $user->id]);

        $defaultRole = Role::where('name', 'User')->first();

        if ($defaultRole) {
            $user->roles()->syncWithoutDetaching([$defaultRole->id => ['assigned_at' => now()]]);
        }
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function splitFullName(?string $fullName): array
    {
        $fullName = trim((string) $fullName);

        if ($fullName === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+/', $fullName, 2);

        return [$parts[0], $parts[1] ?? null];
    }
}
