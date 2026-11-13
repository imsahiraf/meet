<?php

namespace Modules\Meetings\Models;

use Illuminate\Database\Eloquent\Model;

class MeetingEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['meeting_id', 'participant_id', 'type', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }
}
