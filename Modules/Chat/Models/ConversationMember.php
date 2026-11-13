<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class ConversationMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'conversation_id',
        'user_id',
        'joined_at',
        'left_at',
        'role',
        'is_muted',
        'is_archived',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'is_muted' => 'boolean',
            'is_archived' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $member) {
            $member->uuid ??= (string) Str::uuid();
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
