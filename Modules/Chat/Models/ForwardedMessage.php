<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Authentication\Models\User;

class ForwardedMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id',
        'original_message_id',
        'forwarded_by',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function originalMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'original_message_id');
    }

    public function forwardedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forwarded_by');
    }
}
