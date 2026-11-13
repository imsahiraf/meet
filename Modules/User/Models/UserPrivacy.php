<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class UserPrivacy extends Model
{
    use HasFactory;

    protected $table = 'user_privacy';

    protected $fillable = [
        'uuid',
        'user_id',
        'last_seen_visibility',
        'fake_last_seen_at',
        'fake_presence_mode',
        'profile_photo_visibility',
        'about_visibility',
        'read_receipts_enabled',
    ];

    protected function casts(): array
    {
        return [
            'read_receipts_enabled' => 'boolean',
            'fake_last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $privacy) {
            $privacy->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
