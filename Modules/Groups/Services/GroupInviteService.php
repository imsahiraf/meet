<?php

namespace Modules\Groups\Services;

use InvalidArgumentException;
use Modules\Authentication\Models\User;
use Modules\Groups\Exceptions\DuplicateGroupMemberException;
use Modules\Groups\Exceptions\DuplicateJoinRequestException;
use Modules\Groups\Exceptions\GroupMemberLimitReachedException;
use Modules\Groups\Exceptions\InvalidGroupInviteException;
use Modules\Groups\Models\Group;
use Modules\Groups\Models\GroupInvite;
use Modules\Groups\Models\GroupJoinRequest;
use Modules\Groups\Models\GroupRole;
use Modules\Groups\Repositories\GroupInviteRepository;
use Modules\Groups\Repositories\GroupRepository;

class GroupInviteService
{
    public function __construct(
        private readonly GroupRepository $groups,
        private readonly GroupInviteRepository $invites,
    ) {
    }

    /**
     * RAS-3 API15 - invites may be tied to a specific invited user, or
     * left open (invited_user_id null) as a shareable link, per DDS-3.
     */
    public function generateInvite(Group $group, User $actor, ?int $invitedUserId, ?int $expiresInHours): GroupInvite
    {
        $invite = GroupInvite::create([
            'group_id' => $group->id,
            'invited_by' => $actor->id,
            'invited_user_id' => $invitedUserId,
            'expires_at' => $expiresInHours ? now()->addHours($expiresInHours) : now()->addDays(7),
        ]);

        $this->groups->log($group, $actor->id, 'invite_generated', ['invite_id' => $invite->id]);

        return $invite;
    }

    /**
     * RAS-3 API17 - revokes every still-pending invite for the group.
     */
    public function revokeInvite(Group $group, User $actor): void
    {
        $this->invites->revokeActiveInvites($group);
        $this->groups->log($group, $actor->id, 'invite_revoked');
    }

    /**
     * RAS-3 API16 - POST /groups/join.
     */
    public function joinViaInvite(User $user, string $token): Group
    {
        $invite = $this->invites->findActiveByToken($token);

        if (! $invite || $invite->isExpired()) {
            throw new InvalidGroupInviteException();
        }

        if ($invite->invited_user_id && $invite->invited_user_id !== $user->id) {
            throw new InvalidGroupInviteException('This invite was issued to a different user.');
        }

        $group = $invite->group;
        $existing = $this->groups->findExistingMembership($group->id, $user->id);

        if ($existing && $existing->isActive()) {
            throw new DuplicateGroupMemberException();
        }

        if ($this->groups->activeMemberCount($group->id) + 1 > $group->member_limit) {
            throw new GroupMemberLimitReachedException();
        }

        $memberRole = $this->groups->findRole($group->id, GroupRole::MEMBER);

        if ($existing) {
            $existing->forceFill(['left_at' => null, 'joined_at' => now(), 'role_id' => $memberRole->id])->save();
        } else {
            $this->groups->addMemberRow($group, $user, $memberRole);
        }

        $invite->forceFill(['status' => 'accepted', 'accepted_at' => now()])->save();
        $this->groups->log($group, $user->id, 'member_joined_via_invite', ['user_id' => $user->id]);

        return $group;
    }

    /**
     * RAS-3 API18 - approval-required groups only.
     */
    public function submitJoinRequest(Group $group, User $user, ?string $message): GroupJoinRequest
    {
        $existing = $this->groups->findExistingMembership($group->id, $user->id);

        if ($existing && $existing->isActive()) {
            throw new DuplicateGroupMemberException();
        }

        if ($this->invites->findPendingJoinRequest($group->id, $user->id)) {
            throw new DuplicateJoinRequestException();
        }

        if (! $group->approval_required) {
            throw new InvalidArgumentException('This group does not require approval to join.');
        }

        return GroupJoinRequest::create([
            'group_id' => $group->id,
            'user_id' => $user->id,
            'message' => $message,
            'status' => 'pending',
        ]);
    }

    /**
     * RAS-3 API19.
     */
    public function approveJoinRequest(Group $group, User $reviewer, GroupJoinRequest $request): void
    {
        if (! $request->isPending()) {
            throw new InvalidArgumentException('This join request has already been reviewed.');
        }

        if ($this->groups->activeMemberCount($group->id) + 1 > $group->member_limit) {
            throw new GroupMemberLimitReachedException();
        }

        $memberRole = $this->groups->findRole($group->id, GroupRole::MEMBER);
        $existing = $this->groups->findExistingMembership($group->id, $request->user_id);

        if ($existing) {
            $existing->forceFill(['left_at' => null, 'joined_at' => now(), 'role_id' => $memberRole->id])->save();
        } else {
            $this->groups->addMemberRow($group, $request->user, $memberRole);
        }

        $request->forceFill(['status' => 'approved', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()])->save();
        $this->groups->log($group, $reviewer->id, 'join_request_approved', ['user_id' => $request->user_id]);
    }

    /**
     * RAS-3 API20.
     */
    public function rejectJoinRequest(Group $group, User $reviewer, GroupJoinRequest $request): void
    {
        if (! $request->isPending()) {
            throw new InvalidArgumentException('This join request has already been reviewed.');
        }

        $request->forceFill(['status' => 'rejected', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()])->save();
        $this->groups->log($group, $reviewer->id, 'join_request_rejected', ['user_id' => $request->user_id]);
    }
}
