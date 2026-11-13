<?php

namespace Modules\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailMessage extends Model
{
    public const FOLDERS = ['inbox' => 'Inbox', 'starred' => 'Starred', 'sent' => 'Sent', 'drafts' => 'Drafts', 'scheduled' => 'Scheduled', 'snoozed' => 'Snoozed', 'archive' => 'Archive', 'spam' => 'Spam', 'trash' => 'Trash'];

    protected $guarded = ['id'];

    protected $casts = ['to' => 'array', 'cc' => 'array', 'bcc' => 'array', 'seen' => 'boolean', 'starred' => 'boolean', 'has_attachments' => 'boolean', 'sent_at' => 'datetime', 'send_at' => 'datetime', 'snooze_until' => 'datetime', 'send_meta' => 'array'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'account_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MailAttachment::class, 'message_id');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(MailLabel::class, 'mail_label_message', 'message_id', 'label_id');
    }

    /** Same conversation: normalised subject + the people involved. */
    public static function threadKey(?string $subject, array $people): string
    {
        $s = strtolower(trim(preg_replace('/^((re|fwd?|aw|wg)\s*:\s*)+/i', '', (string) $subject)));
        sort($people);

        return hash('sha256', $s . '|' . implode(',', array_unique(array_map('strtolower', $people))));
    }
}
