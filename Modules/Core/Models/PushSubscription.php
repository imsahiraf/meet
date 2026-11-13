<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class PushSubscription extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'fcm_token',
        'user_agent',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $subscription) {
            $subscription->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
