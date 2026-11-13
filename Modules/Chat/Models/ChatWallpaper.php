<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class ChatWallpaper extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'name',
        'type',
        'value',
        'is_system_default',
    ];

    protected function casts(): array
    {
        return [
            'is_system_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $wallpaper) {
            $wallpaper->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
