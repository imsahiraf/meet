<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class BroadcastList extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'owner_id',
        'name',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $list) {
            $list->uuid ??= (string) Str::uuid();
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastListRecipient::class);
    }

    public function recipientUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'broadcast_list_recipients');
    }
}
