<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationKey extends Model
{
    protected $fillable = [
        'conversation_id',
        'key_version',
        'key_material',
        'issued_for_member_ids',
    ];

    protected function casts(): array
    {
        return [
            'issued_for_member_ids' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
