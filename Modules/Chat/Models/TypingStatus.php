<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Authentication\Models\User;

class TypingStatus extends Model
{
    protected $table = 'typing_status';

    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'status',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'updated_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isStale(int $timeoutSeconds): bool
    {
        return $this->updated_at?->diffInSeconds(now()) > $timeoutSeconds;
    }
}
