<?php

namespace Modules\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Authentication\Models\User;

class MailAccount extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['secret'];

    protected $casts = ['sync_state' => 'array', 'is_default' => 'boolean', 'last_sync_at' => 'datetime', 'secret' => 'encrypted'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MailMessage::class, 'account_id');
    }

    public function label(): string
    {
        return $this->display_name ? $this->display_name . ' <' . $this->email . '>' : $this->email;
    }
}
