<?php

namespace Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'session_id',
        'ip_address',
        'device_name',
        'browser',
        'operating_system',
        'platform',
        'login_at',
        'last_activity_at',
        'logout_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'login_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'logout_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $session) {
            $session->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
