<?php

namespace Modules\Groups\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class GroupPoll extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'group_id',
        'created_by',
        'question',
        'allow_multiple_votes',
        'closes_at',
    ];

    protected function casts(): array
    {
        return [
            'allow_multiple_votes' => 'boolean',
            'closes_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $poll) {
            $poll->uuid ??= (string) Str::uuid();
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class, 'poll_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class, 'poll_id');
    }

    public function isClosed(): bool
    {
        return $this->closes_at !== null && $this->closes_at->isPast();
    }
}
