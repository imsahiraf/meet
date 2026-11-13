<?php

namespace Modules\Meetings\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Authentication\Models\User;

class RecurringEvent extends Model
{
    use HasFactory;

    protected $fillable = ['created_by', 'frequency', 'interval', 'occurrences', 'ends_on'];

    protected function casts(): array
    {
        return ['ends_on' => 'date'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }
}
