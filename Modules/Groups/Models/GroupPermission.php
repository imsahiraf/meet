<?php

namespace Modules\Groups\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class GroupPermission extends Model
{
    use HasFactory;

    /**
     * The finite set of permission keys this app understands, per
     * DDS-3 group_permissions "Examples" and RAS-3 API14.
     */
    public const KEYS = [
        'manage_group',
        'manage_members',
        'manage_roles',
        'manage_permissions',
        'pin_messages',
        'delete_messages',
        'send_messages',
        'manage_announcements',
        'manage_polls',
        'manage_events',
        'manage_invites',
        'manage_join_requests',
    ];

    protected $fillable = [
        'uuid',
        'role_id',
        'permission_key',
        'is_allowed',
    ];

    protected function casts(): array
    {
        return [
            'is_allowed' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $permission) {
            $permission->uuid ??= (string) Str::uuid();
        });
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(GroupRole::class, 'role_id');
    }
}
