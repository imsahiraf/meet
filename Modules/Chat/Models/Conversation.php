<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'type',
        'title',
        'last_message_id',
        'last_activity_at',
        'is_archived',
        'disappearing_messages_seconds',
        'e2e_enabled',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',
            'is_archived' => 'boolean',
            'disappearing_messages_seconds' => 'integer',
            'e2e_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $conversation) {
            $conversation->uuid ??= (string) Str::uuid();
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ConversationMember::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    public function isPrivate(): bool
    {
        return $this->type === 'private';
    }
}
