<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class UserPresence extends Model
{
    use HasFactory;

    protected $table = 'user_presence';

    const UPDATED_AT = 'updated_at';

    const CREATED_AT = null;

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'user_id',
        'presence_status',
        'last_seen_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $presence) {
            $presence->uuid ??= (string) Str::uuid();
            $presence->updated_at ??= now();
        });

        static::updating(function (self $presence) {
            $presence->updated_at = now();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
