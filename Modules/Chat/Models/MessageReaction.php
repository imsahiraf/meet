<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class MessageReaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'message_id',
        'user_id',
        'emoji',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $reaction) {
            $reaction->uuid ??= (string) Str::uuid();
        });
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
