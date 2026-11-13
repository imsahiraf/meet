<?php

namespace Modules\Groups\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class GroupMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'group_id',
        'user_id',
        'role_id',
        'joined_at',
        'left_at',
        'is_muted',
        'is_admin_muted',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'is_muted' => 'boolean',
            'is_admin_muted' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $member) {
            $member->uuid ??= (string) Str::uuid();
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(GroupRole::class, 'role_id');
    }

    public function isActive(): bool
    {
        return $this->left_at === null;
    }

    public function hasPermission(string $key): bool
    {
        return $this->role->hasPermission($key);
    }
}
