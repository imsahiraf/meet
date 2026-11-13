<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Authentication\Models\User;

class ChatSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'wallpaper_id',
        'muted_until',
        'notifications_enabled',
    ];

    protected function casts(): array
    {
        return [
            'muted_until' => 'datetime',
            'notifications_enabled' => 'boolean',
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

    public function wallpaper(): BelongsTo
    {
        return $this->belongsTo(ChatWallpaper::class, 'wallpaper_id');
    }

    public function isMuted(): bool
    {
        return $this->muted_until !== null && $this->muted_until->isFuture();
    }
}
