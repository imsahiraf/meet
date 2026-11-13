<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class Archive extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'scope', 'trigger', 'requested_by', 'status',
        'disk_path', 'size_bytes', 'checksum', 'failure_reason', 'delivered_at',
    ];

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $archive) {
            $archive->uuid ??= (string) Str::uuid();
        });
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
