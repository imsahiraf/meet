<?php

namespace Modules\Meetings\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'meeting_id',
        'chat_enabled',
        'whiteboard_enabled',
        'screen_share_enabled',
        'whiteboard_permission',
        'screen_share_permission',
        'auto_mute_on_join',
    ];

    protected function casts(): array
    {
        return [
            'chat_enabled' => 'boolean',
            'whiteboard_enabled' => 'boolean',
            'screen_share_enabled' => 'boolean',
            'auto_mute_on_join' => 'boolean',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}
