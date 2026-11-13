<?php

namespace Modules\Groups\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\Authentication\Models\User;
use Modules\Groups\Models\Group;
use Modules\Groups\Models\GroupMember;

class GroupPolicy
{
    use HandlesAuthorization;

    public function view(User $user, Group $group): bool
    {
        return $this->activeMembership($user, $group) !== null;
    }

    /**
     * FR-074 - editing group info respects the admin_only_edit_info toggle.
     */
    public function update(User $user, Group $group): bool
    {
        $member = $this->activeMembership($user, $group);

        if (! $member) {
            return false;
        }

        if (! $group->admin_only_edit_info) {
            return true;
        }

        return $member->hasPermission('manage_group');
    }

    /**
     * FR-078 - owner only.
     */
    public function delete(User $user, Group $group): bool
    {
        return $group->owner_id === $user->id;
    }

    public function manageMembers(User $user, Group $group): bool
    {
        return $this->activeMembership($user, $group)?->hasPermission('manage_members') ?? false;
    }

    /**
     * FR-072 - role assignment and ownership transfer are owner-only.
     */
    public function manageRoles(User $user, Group $group): bool
    {
        return $group->owner_id === $user->id;
    }

    public function managePermissions(User $user, Group $group): bool
    {
        return $group->owner_id === $user->id;
    }

    /**
     * RAS-3 API40 explicitly allows "Owner / Admin" (unlike role/permission
     * management, which stays owner-only).
     */
    public function manageSettings(User $user, Group $group): bool
    {
        if ($group->owner_id === $user->id) {
            return true;
        }

        return $this->activeMembership($user, $group)?->hasPermission('manage_group') ?? false;
    }

    /**
     * FR-075/FR-079 - invite generation respects admin_only_invite.
     */
    public function manageInvites(User $user, Group $group): bool
    {
        $member = $this->activeMembership($user, $group);

        if (! $member) {
            return false;
        }

        if (! $group->admin_only_invite) {
            return true;
        }

        return $member->hasPermission('manage_invites');
    }

    public function manageJoinRequests(User $user, Group $group): bool
    {
        return $this->activeMembership($user, $group)?->hasPermission('manage_join_requests') ?? false;
    }

    public function manageAnnouncements(User $user, Group $group): bool
    {
        return $this->activeMembership($user, $group)?->hasPermission('manage_announcements') ?? false;
    }

    public function managePolls(User $user, Group $group): bool
    {
        return $this->activeMembership($user, $group)?->hasPermission('manage_polls') ?? false;
    }

    public function manageEvents(User $user, Group $group): bool
    {
        return $this->activeMembership($user, $group)?->hasPermission('manage_events') ?? false;
    }

    /**
     * FR-079 - pinning respects the admin_only_pin toggle.
     */
    public function pinMessages(User $user, Group $group): bool
    {
        $member = $this->activeMembership($user, $group);

        if (! $member) {
            return false;
        }

        if (! $group->admin_only_pin) {
            return true;
        }

        return $member->hasPermission('pin_messages');
    }

    private function activeMembership(User $user, Group $group): ?GroupMember
    {
        $member = $group->relationLoaded('members')
            ? $group->members->firstWhere('user_id', $user->id)
            : $group->members()->where('user_id', $user->id)->first();

        return $member?->isActive() ? $member : null;
    }
}
