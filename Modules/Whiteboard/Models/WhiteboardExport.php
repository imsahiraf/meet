<?php

namespace Modules\Whiteboard\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Authentication\Models\User;
use Modules\Media\Models\Media;
use Modules\Meetings\Models\MeetingParticipant;

class WhiteboardExport extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['whiteboard_id', 'media_id', 'format', 'exported_by', 'exported_by_participant_id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $export) {
            $export->created_at ??= now();
        });
    }

    public function whiteboard(): BelongsTo
    {
        return $this->belongsTo(Whiteboard::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function exportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exported_by');
    }

    public function exportedByParticipant(): BelongsTo
    {
        return $this->belongsTo(MeetingParticipant::class, 'exported_by_participant_id');
    }
}
