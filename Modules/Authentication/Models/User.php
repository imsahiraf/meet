<?php

namespace Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Admin\Models\Admin;
use Modules\Admin\Models\Role;
use Modules\Authentication\Database\Factories\UserFactory;
use Modules\User\Models\UserDevice;
use Modules\User\Models\UserPreference;
use Modules\User\Models\UserPresence;
use Modules\User\Models\UserPrivacy;
use Modules\User\Models\UserProfile;

class User extends Authenticatable implements PasskeyUser
{
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, SoftDeletes;

    protected $fillable = [
        'uuid',
        'full_name',
        'email',
        'phone',
        'password',
        'profile_photo',
        'status',
        'one_view_super_access',
        'must_change_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'one_view_super_access' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->uuid ??= (string) Str::uuid();
        });
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function preferences(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    /**
     * The profile photo as $viewer may see it (Settings > Privacy > Profile photo): yourself always; "nobody" = never;
     * "contacts" = only people in your contacts; "everyone" (the default) = everybody.
     */
    public function photoFor(?self $viewer): ?string
    {
        if (! $this->profile_photo) {
            return null;
        }
        $photo = str_starts_with($this->profile_photo, '/') ? url($this->profile_photo) : $this->profile_photo; // full address for the apps
        if ($viewer && $viewer->id === $this->id) {
            return $photo;
        }
        $rule = $this->privacy?->profile_photo_visibility ?? 'everyone';
        if ($rule === 'nobody') {
            return null;
        }
        if ($rule === 'contacts') {
            return $viewer && \Modules\Contacts\Models\Contact::where('user_id', $this->id)->where('contact_user_id', $viewer->id)->exists() ? $photo : null;
        }

        return $photo;
    }

    public function privacy(): HasOne
    {
        return $this->hasOne(UserPrivacy::class);
    }

    public function presence(): HasOne
    {
        return $this->hasOne(UserPresence::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function emailOtps(): HasMany
    {
        return $this->hasMany(EmailOtp::class);
    }

    public function passwordResetTokens(): HasMany
    {
        return $this->hasMany(PasswordResetToken::class);
    }

    public function emailVerifications(): HasMany
    {
        return $this->hasMany(EmailVerification::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function trustedDevices(): HasMany
    {
        return $this->hasMany(TrustedDevice::class);
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(\Modules\Core\Models\PushSubscription::class);
    }

    public function twoFactorSecret(): HasOne
    {
        return $this->hasOne(TwoFactorSecret::class);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->twoFactorSecret?->isConfirmed() ?? false;
    }

    public function oauthIdentities(): HasMany
    {
        return $this->hasMany(UserOauthIdentity::class);
    }

    /**
     * SSO-created accounts have no password at all (not a random
     * unusable hash) - this is the real signal for "does this account
     * have a fallback login method besides its linked provider(s)?".
     */
    public function hasUsablePassword(): bool
    {
        return $this->password !== null;
    }

    /**
     * This model has no `name` column (it's `full_name`) - the trait's
     * default falls back to email without this override.
     */
    public function getPasskeyDisplayName(): string
    {
        return $this->full_name ?: $this->email;
    }

    public function loginAttempts(): HasMany
    {
        return $this->hasMany(LoginAttempt::class);
    }

    public function admin(): HasOne
    {
        return $this->hasOne(Admin::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot(['assigned_by', 'assigned_at']);
    }

    public function hasRole(string $roleName): bool
    {
        return $this->roles()->where('name', $roleName)->exists();
    }

    public function hasPermission(string $permissionKey): bool
    {
        return $this->roles()
            ->whereHas('permissions', fn ($query) => $query->where('permission_key', $permissionKey))
            ->exists();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
