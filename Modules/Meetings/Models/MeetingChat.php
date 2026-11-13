<?php

namespace Modules\Meetings\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Chat\Models\Conversation;

class MeetingChat extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'meeting_chat';

    protected $fillable = ['uuid', 'meeting_id', 'conversation_id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $chat) {
            $chat->uuid ??= (string) Str::uuid();
            $chat->created_at ??= now();
        });
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
