<?php

namespace Modules\Meetings\Models;

use Illuminate\Database\Eloquent\Model;

class MeetingRecording extends Model
{
    protected $fillable = ['meeting_id', 'user_id', 'path', 'size', 'seconds'];
}
