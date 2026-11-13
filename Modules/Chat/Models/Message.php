<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Media\Models\Media;
use Modules\Media\Models\OneViewMedia;

class Message extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'conversation_id',
        'sender_id',
        'reply_to_message_id',
        'message_type',
        'body',
        'encryption_mode',
        'key_version',
        'iv',
        'media_id',
        'status',
        'edited_at',
        'expires_at',
        'expire_on_read',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
            'expires_at' => 'datetime',
            'expire_on_read' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $message) {
            $message->uuid ??= (string) Str::uuid();
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function replyToMessage(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id');
    }

    public function replyMeta(): HasOne
    {
        return $this->hasOne(MessageReply::class);
    }

    public function forwardMeta(): HasOne
    {
        return $this->hasOne(ForwardedMessage::class);
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(MessageStatus::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    public function readReceipts(): HasMany
    {
        return $this->hasMany(ReadReceipt::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    public function oneViewMedia(): HasOne
    {
        return $this->hasOne(OneViewMedia::class);
    }

    public function isDeletedFor(int $userId): bool
    {
        return DeletedMessage::where('message_id', $this->id)->where('user_id', $userId)->exists();
    }

    /**
     * WhatsApp-style tick semantics for a group of N recipients: "read"
     * only once every recipient has read it, "delivered" only once
     * every recipient has at least received it, otherwise still "sent".
     * Requires the `statuses` relation to already be eager-loaded -
     * this never lazy-loads, to avoid an N+1 across a message list.
     */
    public function aggregateDeliveryStatus(): string
    {
        if ($this->statuses->isEmpty()) {
            return 'sent';
        }

        if ($this->statuses->every(fn (MessageStatus $s) => $s->status === 'read')) {
            return 'read';
        }

        if ($this->statuses->every(fn (MessageStatus $s) => in_array($s->status, ['delivered', 'read'], true))) {
            return 'delivered';
        }

        return 'sent';
    }

    public function starredBy(int $userId): bool
    {
        return StarredMessage::where('message_id', $this->id)->where('user_id', $userId)->exists();
    }

    /**
     * Unlike a star, a pin isn't per-user - it's conversation-wide (see
     * PinnedMessage's unique ['conversation_id', 'message_id']), so this
     * takes no user argument.
     */
    public function isPinned(): bool
    {
        return PinnedMessage::where('message_id', $this->id)->exists();
    }
}
