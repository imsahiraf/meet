<?php

namespace Modules\Calls\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

/**
 * One person's membership of one call. This - not calls.caller_id /
 * calls.receiver_id - is the authoritative answer to "who is in this
 * call", which is what makes a call N-party instead of a fixed pair
 * (see the create_call_participants_table migration for the full
 * data-shape decision).
 *
 * Roles are deliberately only 'caller' (whoever started it) and
 * 'participant'. A call has no host/co-host/moderator concept the way
 * a Meeting does - it's a call, everyone in it is equal, and nobody
 * can mute or eject anybody else.
 */
class CallParticipant extends Model
{
    use HasFactory;

    public const CALLER = 'caller';

    public const PARTICIPANT = 'participant';

    protected $fillable = [
        'uuid',
        'call_id',
        'user_id',
        'role',
        'joined_at',
        'left_at',
        'audio_enabled',
        'video_enabled',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'audio_enabled' => 'boolean',
            'video_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $participant) {
            $participant->uuid ??= (string) Str::uuid();
        });
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Actually in the call right now: has joined (the original receiver
     * hasn't until they accept) and hasn't left. Every membership check
     * in this module goes through this one definition rather than
     * re-deriving it, so "still in the call" can never mean two
     * different things in two different places.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('joined_at')->whereNull('left_at');
    }

    /**
     * Invited and not gone: includes the original receiver while the
     * call is still ringing (they have no joined_at yet). This is the
     * right scope for authorization - a receiver must be able to
     * accept/reject a call they haven't joined - whereas signaling and
     * the participant grid want active() above.
     */
    public function scopePresent(Builder $query): Builder
    {
        return $query->whereNull('left_at');
    }

    public function isActive(): bool
    {
        return $this->joined_at !== null && $this->left_at === null;
    }

    public function mediaState(): array
    {
        return [
            'audio_enabled' => (bool) $this->audio_enabled,
            'video_enabled' => (bool) $this->video_enabled,
        ];
    }
}
