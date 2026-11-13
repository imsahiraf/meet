<?php

namespace Modules\Meetings\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class MeetingParticipant extends Model
{
    use HasFactory;

    public const HOST = 'host';
    public const CO_HOST = 'co_host';
    public const PRESENTER = 'presenter';
    public const PARTICIPANT = 'participant';

    protected $fillable = [
        'uuid',
        'meeting_id',
        'user_id',
        'guest_session_id',
        'role',
        'joined_at',
        'left_at',
        'last_seen_at',
        'microphone_enabled',
        'camera_enabled',
        'screen_sharing',
        'hand_raised',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'microphone_enabled' => 'boolean',
            'camera_enabled' => 'boolean',
            'screen_sharing' => 'boolean',
            'hand_raised' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $participant) {
            $participant->uuid ??= (string) Str::uuid();
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

    public function guestSession(): BelongsTo
    {
        return $this->belongsTo(MeetingGuestSession::class, 'guest_session_id');
    }

    public function isGuest(): bool
    {
        return $this->guest_session_id !== null;
    }

    public function isHostOrCoHost(): bool
    {
        return in_array($this->role, [self::HOST, self::CO_HOST], true);
    }

    public function isActive(): bool
    {
        return $this->left_at === null;
    }

    public function displayName(): string
    {
        return $this->isGuest()
            ? $this->guestSession?->guest_name.' (Guest)'
            : ($this->user?->full_name ?? 'Unknown');
    }
}
