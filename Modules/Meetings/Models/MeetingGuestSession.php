<?php

namespace Modules\Meetings\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingGuestSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'meeting_id',
        'guest_name',
        'session_token',
        'joined_at',
        'expires_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $session) {
            $session->uuid ??= (string) \Illuminate\Support\Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'session_token';
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
