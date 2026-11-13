<?php

namespace Modules\Meetings\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingIceCandidate extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['meeting_id', 'sender_participant_id', 'receiver_participant_id', 'candidate', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $candidate) {
            $candidate->created_at ??= now();
        });
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function senderParticipant(): BelongsTo
    {
        return $this->belongsTo(MeetingParticipant::class, 'sender_participant_id');
    }

    public function receiverParticipant(): BelongsTo
    {
        return $this->belongsTo(MeetingParticipant::class, 'receiver_participant_id');
    }
}
