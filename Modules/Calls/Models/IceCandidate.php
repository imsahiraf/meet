<?php

namespace Modules\Calls\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class IceCandidate extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'call_id',
        'sender_id',
        'receiver_id',
        'candidate',
        'processed_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $candidate) {
            $candidate->uuid ??= (string) Str::uuid();
            $candidate->created_at ??= now();
        });
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}
