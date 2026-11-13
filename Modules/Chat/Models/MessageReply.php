<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Authentication\Models\User;

class MessageReply extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id',
        'replied_to_message_id',
        'replied_to_sender_id',
        'replied_to_type',
        'replied_to_preview',
        'replied_to_preview_iv',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function repliedToMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'replied_to_message_id');
    }

    public function repliedToSender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_to_sender_id');
    }
}
