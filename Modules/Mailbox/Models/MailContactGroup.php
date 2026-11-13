<?php

namespace Modules\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;

/** A name for several addresses (Mail settings > Contact groups); compose adds them to To in one go. */
class MailContactGroup extends Model
{
    protected $guarded = ['id'];
}
