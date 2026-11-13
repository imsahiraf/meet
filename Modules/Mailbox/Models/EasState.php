<?php

namespace Modules\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;

/** What one phone has of one folder: the sync key and {message id: flags}. */
class EasState extends Model
{
    protected $table = 'mail_eas_states';

    protected $guarded = ['id'];

    public function itemsArray(bool $previous = false): array
    {
        return json_decode((string) ($previous ? $this->prev_items : $this->items), true) ?: [];
    }
}
