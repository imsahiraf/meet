<?php

namespace Modules\Meetings\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'meeting_id',
        'duration_seconds',
        'total_participants',
        'guest_count',
        'screen_share_seconds',
        'whiteboard_object_count',
        'chat_message_count',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}
