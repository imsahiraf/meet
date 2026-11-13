<?php

namespace Modules\Media\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class Media extends Model
{
    use HasFactory;

    protected $table = 'media';

    protected $fillable = [
        'uuid',
        'uploader_id',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'media_type',
        'is_voice_note',
        'duration_seconds',
        'is_encrypted_at_rest',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'is_voice_note' => 'boolean',
            'duration_seconds' => 'integer',
            'is_encrypted_at_rest' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $media) {
            $media->uuid ??= (string) Str::uuid();
        });
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }
}
