<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Authentication\Models\User;

class BroadcastListRecipient extends Model
{
    protected $fillable = [
        'broadcast_list_id',
        'user_id',
    ];

    public function broadcastList(): BelongsTo
    {
        return $this->belongsTo(BroadcastList::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
