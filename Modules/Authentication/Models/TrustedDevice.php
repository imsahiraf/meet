<?php

namespace Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TrustedDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'device_identifier',
        'device_name',
        'browser',
        'operating_system',
        'platform',
        'last_used_at',
        'trusted_at',
        'expires_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'trusted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $device) {
            $device->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
