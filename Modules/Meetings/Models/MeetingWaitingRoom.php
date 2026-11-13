<?php

namespace Modules\Meetings\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class MeetingWaitingRoom extends Model
{
    use HasFactory;

    protected $table = 'meeting_waiting_room';

    protected $fillable = [
        'uuid',
        'meeting_id',
        'participant_type',
        'user_id',
        'guest_name',
        'requested_at',
        'admitted_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'admitted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            $entry->uuid ??= (string) Str::uuid();
        });
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function displayName(): string
    {
        return $this->participant_type === 'guest'
            ? $this->guest_name.' (Guest)'
            : ($this->user?->full_name ?? 'Unknown');
    }
}
