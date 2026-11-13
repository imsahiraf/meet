<?php

namespace Modules\Contacts\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class FavoriteContact extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'contact_user_id',
        'sort_order',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $favorite) {
            $favorite->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contactUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contact_user_id');
    }
}
