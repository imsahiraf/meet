<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class UserProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'first_name',
        'last_name',
        'display_name',
        'bio',
        'date_of_birth',
        'gender',
        'website',
        'avatar',
        'cover_photo',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $profile) {
            $profile->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
