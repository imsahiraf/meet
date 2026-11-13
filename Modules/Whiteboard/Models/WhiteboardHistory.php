<?php

namespace Modules\Whiteboard\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Authentication\Models\User;
use Modules\Meetings\Models\MeetingParticipant;

class WhiteboardHistory extends Model
{
    use HasFactory;

    protected $table = 'whiteboard_history';

    public $timestamps = false;

    protected $fillable = [
        'whiteboard_id',
        'whiteboard_object_id',
        'action',
        'previous_state',
        'new_state',
        'actor_id',
        'actor_participant_id',
        'is_undone',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_state' => 'array',
            'new_state' => 'array',
            'is_undone' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            $entry->created_at ??= now();
        });
    }

    public function whiteboard(): BelongsTo
    {
        return $this->belongsTo(Whiteboard::class);
    }

    public function object(): BelongsTo
    {
        return $this->belongsTo(WhiteboardObject::class, 'whiteboard_object_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function actorParticipant(): BelongsTo
    {
        return $this->belongsTo(MeetingParticipant::class, 'actor_participant_id');
    }
}
