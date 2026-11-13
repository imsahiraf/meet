<?php

namespace Modules\Whiteboard\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WhiteboardPage extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['uuid', 'whiteboard_id', 'page_number', 'name', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $page) {
            $page->uuid ??= (string) Str::uuid();
            $page->created_at ??= now();
        });
    }

    public function whiteboard(): BelongsTo
    {
        return $this->belongsTo(Whiteboard::class);
    }

    public function objects(): HasMany
    {
        return $this->hasMany(WhiteboardObject::class)->where('is_deleted', false)->orderBy('z_index')->orderBy('id');
    }
}
