<?php

namespace Modules\Meetings\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Authentication\Models\User;

class CalendarRsvp extends Model
{
    use HasFactory;

    protected $table = 'calendar_rsvp';

    protected $fillable = ['calendar_event_id', 'user_id', 'status'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
