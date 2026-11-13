<?php

namespace Modules\Groups\Repositories;

use Modules\Groups\Models\Group;
use Modules\Groups\Models\GroupInvite;
use Modules\Groups\Models\GroupJoinRequest;

class GroupInviteRepository
{
    public function findActiveByToken(string $token): ?GroupInvite
    {
        return GroupInvite::where('invite_token', $token)->where('status', 'pending')->first();
    }

    public function findPendingJoinRequest(int $groupId, int $userId): ?GroupJoinRequest
    {
        return GroupJoinRequest::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->first();
    }

    public function revokeActiveInvites(Group $group): void
    {
        GroupInvite::where('group_id', $group->id)->where('status', 'pending')->update(['status' => 'revoked']);
    }
}
