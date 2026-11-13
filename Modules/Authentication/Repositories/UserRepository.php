<?php

namespace Modules\Authentication\Repositories;

use Modules\Authentication\DTO\RegisterUserData;
use Modules\Authentication\Models\User;

class UserRepository
{
    public function findByEmail(string $email): ?User
    {
        return User::where('email', mb_strtolower($email))->first();
    }

    public function findByPhone(string $phone): ?User
    {
        return User::where('phone', $phone)->first();
    }

    /**
     * Resolve a user by either their email address or phone number,
     * as accepted by the RAS-1 `login` field.
     */
    public function findByEmailOrPhone(string $login): ?User
    {
        return User::where('email', mb_strtolower($login))
            ->orWhere('phone', $login)
            ->first();
    }

    public function existsByEmail(string $email): bool
    {
        return User::where('email', mb_strtolower($email))->exists();
    }

    public function existsByPhone(string $phone): bool
    {
        return User::where('phone', $phone)->exists();
    }

    public function create(RegisterUserData $data): User
    {
        return User::create([
            'full_name' => $data->fullName,
            'email' => $data->email,
            'phone' => $data->phone,
            'password' => $data->password,
            'status' => 'pending',
        ]);
    }

    /**
     * SSO-created accounts have no phone (the provider never gives us
     * one) and no password at all (not a random unusable hash - see
     * User::hasUsablePassword()), and are auto-verified/active since
     * the provider already verified this email address on our behalf.
     */
    public function createFromSso(string $email, string $fullName): User
    {
        // forceCreate(), not create() - email_verified_at isn't
        // mass-assignable (deliberately, it's a system-set field
        // everywhere else too - see markEmailVerified() below), and
        // this is the one legitimate path that needs to set it at
        // creation time instead of via a separate verify step.
        return User::forceCreate([
            'full_name' => $fullName,
            'email' => mb_strtolower($email),
            'phone' => null,
            'password' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    public function markEmailVerified(User $user): User
    {
        $user->forceFill([
            'email_verified_at' => now(),
            'status' => 'active',
        ])->save();

        return $user;
    }

    public function recordLogin(User $user): User
    {
        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }

    public function updatePassword(User $user, string $hashedPassword): User
    {
        $user->forceFill(['password' => $hashedPassword])->save();

        return $user;
    }
}
