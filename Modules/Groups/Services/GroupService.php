<?php

namespace Modules\Groups\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\ConversationMember;
use Modules\Groups\Exceptions\CannotRemoveOwnerException;
use Modules\Groups\Exceptions\DuplicateGroupMemberException;
use Modules\Groups\Exceptions\GroupMemberLimitReachedException;
use Modules\Groups\Exceptions\OwnershipTransferRequiredException;
use Modules\Groups\Models\Group;
use Modules\Groups\Models\GroupMember;
use Modules\Groups\Models\GroupRole;
use Modules\Groups\Repositories\GroupRepository;

class GroupService
{
    public function __construct(
        private readonly GroupRepository $groups,
    ) {
    }

    /**
     * FR-071 - creator becomes owner; RAS-3 API1 business rule "Conversation
     * is created automatically."
     */
    public function create(User $owner, string $name, ?string $description, ?string $avatar, array $memberIds): Group
    {
        $memberIds = array_values(array_unique(array_filter($memberIds, fn ($id) => (int) $id !== $owner->id)));

        if (count($memberIds) < 1) {
            throw new InvalidArgumentException('Select at least one other member to create a group.');
        }

        return $this->groups->create($owner, $name, $description, $avatar, $memberIds);
    }

    public function listForUser(User $user, ?string $search = null): LengthAwarePaginator
    {
        return $this->groups->listForUser($user, $search);
    }

    /**
     * FR-074 - editable fields; all changes are logged.
     */
    public function update(Group $group, User $actor, array $data): Group
    {
        $group->fill(array_intersect_key($data, array_flip(['name', 'description', 'avatar'])));

        if ($group->isDirty()) {
            $group->save();
            $this->groups->log($group, $actor->id, 'group_updated', $group->getChanges());

            if (array_key_exists('name', $data)) {
                $group->conversation()->update(['title' => $data['name']]);
            }
        }

        return $group;
    }

    /**
     * FR-078 - owner-only, soft delete.
     */
    public function delete(Group $group, User $actor): void
    {
        $group->forceFill(['status' => 'deleted'])->save();
        $this->groups->log($group, $actor->id, 'group_deleted');
        $group->delete();
    }

    /**
     * FR-075 - search-added members; duplicate/limit checks.
     */
    public function addMembers(Group $group, User $actor, array $userIds): void
    {
        $activeCount = $this->groups->activeMemberCount($group->id);
        $memberRole = $this->groups->findRole($group->id, GroupRole::MEMBER);

        foreach (array_unique($userIds) as $userId) {
            $user = User::findOrFail($userId);
            $existing = $this->groups->findExistingMembership($group->id, $user->id);

            if ($existing && $existing->isActive()) {
                throw new DuplicateGroupMemberException();
            }

            if ($activeCount + 1 > $group->member_limit) {
                throw new GroupMemberLimitReachedException();
            }

            if ($existing) {
                $existing->forceFill(['left_at' => null, 'joined_at' => now(), 'role_id' => $memberRole->id])->save();
                ConversationMember::where('conversation_id', $group->conversation_id)
                    ->where('user_id', $user->id)
                    ->update(['left_at' => null]);
            } else {
                $this->groups->addMemberRow($group, $user, $memberRole);
            }

            $activeCount++;
            $this->groups->log($group, $actor->id, 'member_added', ['user_id' => $user->id]);
        }
    }

    /**
     * FR-076 - owner cannot be removed via this path.
     */
    public function removeMember(Group $group, User $actor, int $userId): void
    {
        $member = $this->groups->findExistingMembership($group->id, $userId);

        if (! $member || ! $member->isActive()) {
            throw new InvalidArgumentException('This user is not a member of the group.');
        }

        if ($member->role->isOwnerRole()) {
            throw new CannotRemoveOwnerException();
        }

        $this->markLeft($group, $member);
        $this->groups->log($group, $actor->id, 'member_removed', ['user_id' => $userId]);
    }

    public function bulkRemoveMembers(Group $group, User $actor, array $userIds): void
    {
        foreach (array_unique($userIds) as $userId) {
            $this->removeMember($group, $actor, (int) $userId);
        }
    }

    /**
     * FR-077 - the owner must transfer ownership before leaving, unless
     * they are the group's last remaining member.
     */
    public function leave(Group $group, User $actor): void
    {
        $member = $this->groups->findExistingMembership($group->id, $actor->id);

        if (! $member || ! $member->isActive()) {
            throw new InvalidArgumentException('You are not a member of this group.');
        }

        if ($member->role->isOwnerRole() && $this->groups->activeMemberCount($group->id) > 1) {
            throw new OwnershipTransferRequiredException();
        }

        $this->markLeft($group, $member);
        $this->groups->log($group, $actor->id, 'member_left', ['user_id' => $actor->id]);

        if ($member->role->isOwnerRole()) {
            $this->delete($group, $actor);
        }
    }

    /**
     * FR-072 - Owner-only; ownership can be transferred to any active member.
     */
    public function transferOwnership(Group $group, User $actor, int $newOwnerUserId): void
    {
        $newOwnerMember = $this->groups->findExistingMembership($group->id, $newOwnerUserId);

        if (! $newOwnerMember || ! $newOwnerMember->isActive()) {
            throw new InvalidArgumentException('The new owner must be an active member of the group.');
        }

        $ownerRole = $this->groups->findRole($group->id, GroupRole::OWNER);
        $adminRole = $this->groups->findRole($group->id, GroupRole::ADMIN);

        DB::transaction(function () use ($group, $actor, $newOwnerMember, $ownerRole, $adminRole) {
            $currentOwnerMember = $this->groups->findExistingMembership($group->id, $group->owner_id);
            $currentOwnerMember?->forceFill(['role_id' => $adminRole->id])->save();

            $newOwnerMember->forceFill(['role_id' => $ownerRole->id])->save();
            $group->forceFill(['owner_id' => $newOwnerMember->user_id])->save();
        });

        $this->groups->log($group, $actor->id, 'ownership_transferred', ['new_owner_id' => $newOwnerUserId]);
    }

    /**
     * FR-072 - Owner-only role assignment (promote/demote).
     */
    public function assignRole(Group $group, User $actor, int $memberId, int $roleId): GroupMember
    {
        $member = GroupMember::where('group_id', $group->id)->findOrFail($memberId);
        $role = GroupRole::where('group_id', $group->id)->findOrFail($roleId);

        if ($member->role->isOwnerRole() || $role->isOwnerRole()) {
            throw new CannotRemoveOwnerException('Ownership can only change via the transfer-ownership action.');
        }

        $member->forceFill(['role_id' => $role->id])->save();
        $this->groups->log($group, $actor->id, 'role_changed', ['user_id' => $member->user_id, 'role' => $role->role_name]);

        return $member->fresh('role');
    }

    /**
     * FR-079 - group-level permission toggles + per-role permission matrix.
     */
    public function updatePermissions(Group $group, User $actor, array $togglesData, array $rolePermissions = []): Group
    {
        $group->fill(array_intersect_key($togglesData, array_flip([
            'admin_only_messaging', 'admin_only_edit_info', 'admin_only_invite',
            'admin_only_pin', 'allow_member_reactions', 'allow_member_file_sharing',
        ])));
        $group->save();

        foreach ($rolePermissions as $roleId => $permissions) {
            $role = $group->roles()->find($roleId);

            if (! $role || $role->isOwnerRole()) {
                continue;
            }

            foreach ($permissions as $key => $isAllowed) {
                $role->permissions()->where('permission_key', $key)->update(['is_allowed' => (bool) $isAllowed]);
            }
        }

        $this->groups->log($group, $actor->id, 'permissions_updated');

        return $group->fresh(['roles.permissions']);
    }

    public function updateSettings(Group $group, User $actor, array $data): Group
    {
        $group->fill(array_intersect_key($data, array_flip(['approval_required', 'member_limit'])));
        $group->save();
        $this->groups->log($group, $actor->id, 'settings_updated');

        return $group;
    }

    /**
     * Personal (per-member) mute preference - mirrors 1:1 chat's mute
     * semantics rather than a group-wide broadcast setting.
     */
    public function toggleMute(Group $group, User $actor, bool $muted): void
    {
        $this->groups->findExistingMembership($group->id, $actor->id)?->update(['is_muted' => $muted]);
    }

    /**
     * Personal (per-member) archive preference, reusing the existing
     * Chat conversation_members.is_archived column.
     */
    public function toggleArchive(Group $group, User $actor, bool $archived): void
    {
        ConversationMember::where('conversation_id', $group->conversation_id)
            ->where('user_id', $actor->id)
            ->update(['is_archived' => $archived]);
    }

    /**
     * RAS-3 API40 - "Export Group Information". Formats: PDF/Excel per
     * RAS-3, but neither library is installed in this project; CSV is
     * a functional stand-in that needs no new dependency and opens
     * directly in Excel/Sheets.
     */
    public function exportToCsv(Group $group): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['Group', $group->name]);
        fputcsv($handle, ['Description', (string) $group->description]);
        fputcsv($handle, ['Created', $group->created_at->toDateTimeString()]);
        fputcsv($handle, []);
        fputcsv($handle, ['Member', 'Role', 'Joined At']);

        foreach ($group->activeMembers()->with(['user', 'role'])->get() as $member) {
            fputcsv($handle, [$member->user->full_name, $member->role->role_name, $member->joined_at->toDateTimeString()]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function markLeft(Group $group, GroupMember $member): void
    {
        $member->forceFill(['left_at' => now()])->save();

        ConversationMember::where('conversation_id', $group->conversation_id)
            ->where('user_id', $member->user_id)
            ->update(['left_at' => now()]);
    }
}
