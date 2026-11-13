<?php

namespace Modules\User\Repositories;

use Modules\Authentication\Models\User;
use Modules\User\Models\UserPreference;
use Modules\User\Models\UserPresence;
use Modules\User\Models\UserPrivacy;
use Modules\User\Models\UserProfile;

class UserProfileRepository
{
    public function updateProfile(User $user, array $attributes): UserProfile
    {
        $profile = $user->profile ?? $user->profile()->create([]);
        $profile->update($attributes);

        return $profile;
    }

    public function updatePreferences(User $user, array $attributes): UserPreference
    {
        $preferences = $user->preferences ?? $user->preferences()->create([]);
        $preferences->update($attributes);

        return $preferences;
    }

    public function updatePrivacy(User $user, array $attributes): UserPrivacy
    {
        $privacy = $user->privacy ?? $user->privacy()->create([]);
        $privacy->update($attributes);

        return $privacy;
    }

    public function updatePresence(User $user, string $status): UserPresence
    {
        $presence = $user->presence ?? $user->presence()->create([]);
        $presence->update(['presence_status' => $status, 'last_seen_at' => now()]);

        return $presence;
    }
}
