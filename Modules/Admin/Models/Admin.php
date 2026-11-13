<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class Admin extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'admin_level',
        'is_super_admin',
    ];

    protected function casts(): array
    {
        return [
            'is_super_admin' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $admin) {
            $admin->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
