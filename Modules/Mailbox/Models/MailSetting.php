<?php

namespace Modules\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;

class MailSetting extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = ['google_token'];

    protected $casts = ['vacation_on' => 'boolean', 'drive_archive' => 'boolean', 'contacts_sync' => 'boolean', 'vacation_until' => 'date',
        'google_token' => 'encrypted:array', 'google_synced_at' => 'datetime', 'vacation_sent' => 'array'];

    public static function for(int $userId): self
    {
        return self::firstOrCreate(['user_id' => $userId]);
    }

    public function quotaBytes(): int
    {
        return (int) ($this->quota_mb ?: config('mailbox.free_quota_mb', 30)) * 1048576;
    }
}
