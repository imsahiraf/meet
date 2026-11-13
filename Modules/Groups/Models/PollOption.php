<?php

namespace Modules\Groups\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PollOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'poll_id',
        'option_text',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $option) {
            $option->uuid ??= (string) Str::uuid();
        });
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(GroupPoll::class, 'poll_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class, 'option_id');
    }
}
