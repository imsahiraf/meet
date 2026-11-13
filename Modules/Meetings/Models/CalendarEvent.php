<?php

namespace Modules\Meetings\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class CalendarEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'created_by',
        'meeting_id',
        'event_type',
        'title',
        'description',
        'start_at',
        'end_at',
        'timezone',
        'recurring_event_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $event) {
            $event->uuid ??= (string) Str::uuid();
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function recurringEvent(): BelongsTo
    {
        return $this->belongsTo(RecurringEvent::class);
    }

    public function rsvps(): HasMany
    {
        return $this->hasMany(CalendarRsvp::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(CalendarReminder::class);
    }
}
