<?php

namespace Modules\Contacts\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class BlockedUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'blocked_user_id',
        'reason',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $blocked) {
            $blocked->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function blockedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_user_id');
    }
}
