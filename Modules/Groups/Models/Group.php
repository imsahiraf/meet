<?php

namespace Modules\Groups\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\Conversation;

class Group extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'conversation_id',
        'owner_id',
        'community_id',
        'name',
        'description',
        'avatar',
        'invite_code',
        'member_limit',
        'approval_required',
        'admin_only_messaging',
        'admin_only_edit_info',
        'admin_only_invite',
        'admin_only_pin',
        'allow_member_reactions',
        'allow_member_file_sharing',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'member_limit' => 'integer',
            'approval_required' => 'boolean',
            'admin_only_messaging' => 'boolean',
            'admin_only_edit_info' => 'boolean',
            'admin_only_invite' => 'boolean',
            'admin_only_pin' => 'boolean',
            'allow_member_reactions' => 'boolean',
            'allow_member_file_sharing' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $group) {
            $group->uuid ??= (string) Str::uuid();
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(GroupRole::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->whereNull('left_at');
    }

    public function invites(): HasMany
    {
        return $this->hasMany(GroupInvite::class);
    }

    public function joinRequests(): HasMany
    {
        return $this->hasMany(GroupJoinRequest::class);
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(GroupAnnouncement::class);
    }

    public function polls(): HasMany
    {
        return $this->hasMany(GroupPoll::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(GroupEvent::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(GroupMedia::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(GroupLog::class);
    }
}
