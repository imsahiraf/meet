<?php

namespace Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserOauthIdentity extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'provider',
        'provider_user_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $identity) {
            $identity->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
