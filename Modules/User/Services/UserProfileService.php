<?php

namespace Modules\User\Services;

use Modules\Authentication\Models\User;
use Modules\Core\Services\PushNotificationService;
use Modules\User\Models\UserPreference;
use Modules\User\Models\UserPresence;
use Modules\User\Models\UserPrivacy;
use Modules\User\Models\UserProfile;
use Modules\User\Repositories\UserProfileRepository;

class UserProfileService
{
    private const NOTIFY_TOGGLE_KEYS = ['notify_messages', 'notify_calls', 'notify_meetings', 'notify_contact_requests'];

    public function __construct(
        private readonly UserProfileRepository $profiles,
        private readonly PushNotificationService $push,
    ) {
    }

    /**
     * SRS Ch2 - Profile Management.
     */
    public function updateProfile(User $user, array $attributes): UserProfile
    {
        return $this->profiles->updateProfile($user, $attributes);
    }

    /**
     * SRS Ch2 - Preferences (theme, language, timezone, auto-download).
     *
     * A single Web Push subscription row serves every notify_* trigger
     * indiscriminately (there's no per-type subscription), so turning
     * one toggle off alone doesn't warrant tearing anything down - it
     * would still be wanted for the others. Only when the save leaves
     * every notify_* toggle false at once does this actually
     * unsubscribe every device, since at that point nothing would ever
     * be pushed to them anyway.
     */
    public function updatePreferences(User $user, array $attributes): UserPreference
    {
        $preferences = $this->profiles->updatePreferences($user, $attributes);

        $allNotificationsNowOff = collect(self::NOTIFY_TOGGLE_KEYS)
            ->every(fn (string $key) => ! $preferences->{$key});

        if ($allNotificationsNowOff) {
            $this->push->unsubscribeAll($user);
        }

        return $preferences;
    }

    /**
     * SRS Ch2 - Privacy visibility settings. fake_last_seen_at and
     * fake_presence_mode (Super Access only - see
     * Documentation/SUPER.md) are silently dropped for anyone else,
     * rather than rejecting the request outright - they're harmless
     * no-op fields for a regular user, not a validation error worth
     * surfacing.
     */
    public function updatePrivacy(User $user, array $attributes): UserPrivacy
    {
        if (! $user->one_view_super_access) {
            unset($attributes['fake_last_seen_at'], $attributes['fake_presence_mode']);
        }

        return $this->profiles->updatePrivacy($user, $attributes);
    }

    /**
     * FR-032 - presence heartbeat.
     */
    public function updatePresence(User $user, string $status): UserPresence
    {
        return $this->profiles->updatePresence($user, $status);
    }
}
