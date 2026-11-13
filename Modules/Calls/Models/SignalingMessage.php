<?php

namespace Modules\Calls\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class SignalingMessage extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'call_id',
        'sender_id',
        'receiver_id',
        'signal_type',
        'payload',
        'processed_at',
        'expires_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $message) {
            $message->uuid ??= (string) Str::uuid();
            $message->created_at ??= now();
            $message->expires_at ??= now()->addMinutes(5);
        });
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
