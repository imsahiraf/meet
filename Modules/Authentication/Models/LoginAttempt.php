<?php

namespace Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class LoginAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'email',
        'phone',
        'ip_address',
        'user_agent',
        'login_result',
        'failure_reason',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $attempt) {
            $attempt->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
