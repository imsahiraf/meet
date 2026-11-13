<?php

namespace Modules\Media\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\Message;

class OneViewMedia extends Model
{
    use HasFactory;

    protected $table = 'one_view_media';

    protected $fillable = [
        'uuid',
        'message_id',
        'viewed_by',
        'viewed_at',
    ];

    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $item) {
            $item->uuid ??= (string) Str::uuid();
        });
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function viewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'viewed_by');
    }

    public function isConsumed(): bool
    {
        return $this->viewed_at !== null;
    }
}
