<?php

namespace Modules\Meetings\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class Meeting extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'organizer_id',
        'meeting_code',
        'title',
        'description',
        'meeting_type',
        'password',
        'scheduled_start',
        'scheduled_end',
        'timezone',
        'actual_start',
        'actual_end',
        'guest_enabled',
        'waiting_room_enabled',
        'status',
        'parent_meeting_id',
        'breakout_name',
        'summary',
        'summary_at',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'actual_start' => 'datetime',
            'actual_end' => 'datetime',
            'guest_enabled' => 'boolean',
            'waiting_room_enabled' => 'boolean',
            'summary_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $meeting) {
            $meeting->uuid ??= (string) Str::uuid();
        });
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    public function activeParticipants(): HasMany
    {
        return $this->participants()->whereNull('left_at');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(MeetingInvitation::class);
    }

    public function guestSessions(): HasMany
    {
        return $this->hasMany(MeetingGuestSession::class);
    }

    public function waitingRoomEntries(): HasMany
    {
        return $this->hasMany(MeetingWaitingRoom::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(MeetingSetting::class);
    }

    public function chat(): HasOne
    {
        return $this->hasOne(MeetingChat::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MeetingLog::class);
    }

    public function report(): HasOne
    {
        return $this->hasOne(MeetingReport::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['waiting', 'active', 'locked'], true);
    }

    public function hasPassword(): bool
    {
        return filled($this->password);
    }

    /**
     * Guest-created meetings have no organizer - fall back to the
     * guest who created it (the participant with role=host and no
     * user_id).
     */
    public function hostDisplayName(): string
    {
        if ($this->organizer) {
            return $this->organizer->full_name;
        }

        $hostParticipant = $this->participants()
            ->where('role', MeetingParticipant::HOST)
            ->whereNull('user_id')
            ->first();

        return $hostParticipant?->guestSession?->guest_name ?? 'A guest';
    }
}
