<?php

namespace Modules\Groups\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class Community extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'owner_id',
        'name',
        'description',
        'avatar',
        'announcement_group_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $community) {
            $community->uuid ??= (string) Str::uuid();
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function announcementGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'announcement_group_id');
    }
}
