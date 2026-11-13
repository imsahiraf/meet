<?php

namespace Modules\Groups\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class GroupRole extends Model
{
    use HasFactory;

    public const OWNER = 'Owner';

    public const ADMIN = 'Admin';

    public const MODERATOR = 'Moderator';

    public const MEMBER = 'Member';

    protected $fillable = [
        'uuid',
        'group_id',
        'role_name',
        'description',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $role) {
            $role->uuid ??= (string) Str::uuid();
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(GroupPermission::class, 'role_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class, 'role_id');
    }

    public function isOwnerRole(): bool
    {
        return $this->role_name === self::OWNER;
    }

    public function hasPermission(string $key): bool
    {
        if ($this->isOwnerRole()) {
            return true;
        }

        return $this->permissions->firstWhere('permission_key', $key)?->is_allowed ?? false;
    }
}
