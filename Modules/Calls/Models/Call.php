<?php

namespace Modules\Calls\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\Conversation;

/**
 * A voice/video call with 2..N people in it.
 *
 * caller_id/receiver_id are still here on purpose but are NOT the
 * membership list any more - see the create_call_participants_table
 * migration for the full reasoning. Short version: caller_id is who
 * started it, receiver_id is who it was originally started toward (and
 * therefore the only person who may accept/reject it while it's still
 * ringing); `participants` is the authoritative answer to "who is in
 * this call", and a 1:1 call is just the N=2 case.
 */
class Call extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'caller_id',
        'receiver_id',
        'conversation_id',
        'call_type',
        'call_status',
        'started_at',
        'answered_at',
        'ended_at',
        'duration_seconds',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $call) {
            $call->uuid ??= (string) Str::uuid();
        });
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CallParticipant::class);
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function history(): HasOne
    {
        return $this->hasOne(CallHistory::class);
    }

    public function signalingMessages(): HasMany
    {
        return $this->hasMany(SignalingMessage::class);
    }

    public function iceCandidates(): HasMany
    {
        return $this->hasMany(IceCandidate::class);
    }

    public function statistics(): HasOne
    {
        return $this->hasOne(CallStatistic::class);
    }

    public function isActive(): bool
    {
        return in_array($this->call_status, ['ringing', 'accepted'], true);
    }

    /**
     * Everyone currently in the call - joined and not left. Reads
     * through the relation (eager-loaded by the service/resource layer)
     * rather than firing a fresh query per call site, which with N
     * participants and per-pair signaling would otherwise multiply.
     */
    public function activeParticipants(): Collection
    {
        return $this->participants->filter(fn (CallParticipant $p) => $p->isActive())->values();
    }

    /**
     * Invited and not gone - includes the original receiver while the
     * call is still ringing (they have no joined_at until they accept).
     * This is the membership definition authorization uses; the grid and
     * signaling use activeParticipants() instead.
     */
    public function presentParticipants(): Collection
    {
        return $this->participants->filter(fn (CallParticipant $p) => $p->left_at === null)->values();
    }

    public function participantFor(int $userId): ?CallParticipant
    {
        return $this->participants->firstWhere('user_id', $userId);
    }

    public function hasParticipant(int $userId): bool
    {
        $participant = $this->participantFor($userId);

        return $participant !== null && $participant->left_at === null;
    }

    /**
     * The N-aware replacement for the old otherPartyId(): every OTHER
     * person currently in the call, as user ids. With 3+ people an
     * offer/answer/ICE candidate is between a specific PAIR inside the
     * larger call, so callers fan out one signal per id instead of
     * assuming a single "other side" exists at all.
     */
    public function otherActiveParticipantIds(int $userId): array
    {
        return $this->activeParticipants()
            ->reject(fn (CallParticipant $p) => $p->user_id === $userId)
            ->pluck('user_id')
            ->all();
    }

    /**
     * Per-person mute/camera-off state, keyed by user id via that
     * person's own participant row - it used to be keyed by the literal
     * role strings 'caller'/'receiver' in a JSON column, which cannot
     * describe a third person at all. Missing means "on": a call starts
     * with audio and video enabled, matching how the local UI already
     * defaults before anyone touches a control.
     */
    public function mediaStateFor(int $userId): array
    {
        $participant = $this->participantFor($userId);

        return $participant
            ? $participant->mediaState()
            : ['audio_enabled' => true, 'video_enabled' => true];
    }
}
