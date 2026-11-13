<?php

namespace Modules\Calls\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CallStatistic extends Model
{
    use HasFactory;

    protected $table = 'call_statistics';

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'call_id',
        'packet_loss_percent',
        'avg_latency_ms',
        'jitter_ms',
        'connection_time_ms',
        'disconnect_count',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'packet_loss_percent' => 'float',
            'avg_latency_ms' => 'integer',
            'jitter_ms' => 'integer',
            'connection_time_ms' => 'integer',
            'disconnect_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $stat) {
            $stat->uuid ??= (string) Str::uuid();
            $stat->created_at ??= now();
        });
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}
