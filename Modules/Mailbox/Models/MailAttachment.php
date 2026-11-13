<?php

namespace Modules\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailAttachment extends Model
{
    protected $guarded = ['id'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'message_id');
    }
}
