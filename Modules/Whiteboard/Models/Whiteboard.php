<?php

namespace Modules\Whiteboard\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Meetings\Models\Meeting;
use Modules\Meetings\Models\MeetingParticipant;

class Whiteboard extends Model
{
    use HasFactory;

    protected $fillable = ['uuid', 'meeting_id', 'title', 'created_by', 'created_by_participant_id', 'permission_mode', 'status'];

    protected static function booted(): void
    {
        static::creating(function (self $whiteboard) {
            $whiteboard->uuid ??= (string) Str::uuid();
        });
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function creatorParticipant(): BelongsTo
    {
        return $this->belongsTo(MeetingParticipant::class, 'created_by_participant_id');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(WhiteboardPage::class)->orderBy('page_number');
    }

    public function history(): HasMany
    {
        return $this->hasMany(WhiteboardHistory::class);
    }

    public function exports(): HasMany
    {
        return $this->hasMany(WhiteboardExport::class);
    }

    public function canDraw(int $userId, string $role): bool
    {
        return match ($this->permission_mode) {
            'host_only' => $role === 'host',
            'host_and_co_host' => in_array($role, ['host', 'co_host'], true),
            'view_only' => false,
            default => true,
        };
    }
}
