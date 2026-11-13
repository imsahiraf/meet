<?php

namespace Modules\Whiteboard\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Meetings\Models\MeetingParticipant;

class WhiteboardObject extends Model
{
    use HasFactory;

    protected $table = 'whiteboard_objects';

    protected $fillable = [
        'uuid',
        'whiteboard_page_id',
        'tool',
        'data',
        'color',
        'thickness',
        'z_index',
        'created_by',
        'created_by_participant_id',
        'is_deleted',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'is_deleted' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $object) {
            $object->uuid ??= (string) Str::uuid();
        });
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(WhiteboardPage::class, 'whiteboard_page_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function creatorParticipant(): BelongsTo
    {
        return $this->belongsTo(MeetingParticipant::class, 'created_by_participant_id');
    }

    /**
     * "Alice" for a real user, "Bob (Guest)" for a guest - the only
     * one of the pair actually populated on this row.
     */
    public function creatorName(): string
    {
        if ($this->created_by_participant_id) {
            return $this->creatorParticipant?->displayName() ?? 'Unknown';
        }

        return $this->creator?->full_name ?? 'Unknown';
    }
}
