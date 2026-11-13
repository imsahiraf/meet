<?php

namespace Modules\Contacts\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'contact_user_id',
        'nickname',
        'is_favorite',
        'muted_until',
    ];

    protected function casts(): array
    {
        return [
            'is_favorite' => 'boolean',
            'muted_until' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $contact) {
            $contact->uuid ??= (string) Str::uuid();
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

    public function isMuted(): bool
    {
        return $this->muted_until !== null && $this->muted_until->isFuture();
    }
}
