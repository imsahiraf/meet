<?php

namespace Modules\Calls\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class CallHistory extends Model
{
    use HasFactory;

    protected $table = 'call_history';

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'call_id',
        'caller_id',
        'receiver_id',
        'participant_count',
        'duration_seconds',
        'end_reason',
        'network_quality',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'participant_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $history) {
            $history->uuid ??= (string) Str::uuid();
            $history->created_at ??= now();
        });
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }
}
