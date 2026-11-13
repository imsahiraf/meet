<?php

namespace Modules\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;

/** A phone that syncs mail over ActiveSync. */
class EasDevice extends Model
{

    protected $table = 'mail_eas_devices';

    protected $guarded = ['id'];

    protected $casts = ['last_seen_at' => 'datetime'];
}
