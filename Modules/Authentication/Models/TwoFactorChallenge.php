<?php

namespace Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TwoFactorChallenge extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'remember',
        'expires_at',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'remember' => 'boolean',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $challenge) {
            $challenge->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
