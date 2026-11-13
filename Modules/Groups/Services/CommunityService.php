<?php

namespace Modules\Groups\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Models\User;
use Modules\Groups\Models\Community;
use Modules\Groups\Models\Group;
use Modules\Groups\Models\GroupAnnouncement;
use Modules\Groups\Repositories\GroupRepository;

class CommunityService
{
    public function __construct(
        private readonly GroupRepository $groups,
        private readonly GroupContentService $groupContent,
    ) {
    }

    /**
     * Auto-creates a default "Announcements" group alongside the
     * community - reuses GroupRepository::create() directly rather
     * than GroupService::create() (which requires at least one other
     * member) since this group starts owner-only.
     */
    public function create(User $owner, string $name, ?string $description): Community
    {
        return DB::transaction(function () use ($owner, $name, $description) {
            $community = Community::create([
                'owner_id' => $owner->id,
                'name' => $name,
                'description' => $description,
            ]);

            $announcementsGroup = $this->groups->create($owner, 'Announcements', 'Community-wide announcements for '.$name, null, []);
            $announcementsGroup->forceFill(['community_id' => $community->id, 'admin_only_messaging' => true])->save();

            $community->forceFill(['announcement_group_id' => $announcementsGroup->id])->save();

            return $community;
        });
    }

    public function listVisibleForUser(User $user): Collection
    {
        return Community::where('owner_id', $user->id)
            ->orWhereHas('groups.activeMembers', fn ($q) => $q->where('user_id', $user->id))
            ->withCount('groups')
            ->orderByDesc('created_at')
            ->get();
    }

    public function show(User $user, Community $community): Community
    {
        $this->assertCanView($user, $community);

        return $community->load(['owner', 'groups' => fn ($q) => $q->withCount('activeMembers'), 'announcementGroup.announcements.creator']);
    }

    /**
     * "Add an existing group you own" - the community owner, or an
     * admin of the community's Announcements group (the closest thing
     * this community-has-no-membership-table model has to "community
     * admin" - see isCommunityAdmin()), may attach a group. The group
     * being attached must still be owned by the person attaching it
     * (mirrors GroupPolicy::delete()'s owner-only convention for that
     * group specifically), and a group can only ever belong to one
     * community at a time.
     */
    public function attachGroup(User $user, Community $community, Group $group): void
    {
        $this->assertCanManage($user, $community);

        if ($group->owner_id !== $user->id) {
            throw new AuthorizationException('You can only add a group you own.');
        }

        if ($group->community_id !== null) {
            throw ValidationException::withMessages(['group_id' => ['This group already belongs to a community.']]);
        }

        $group->forceFill(['community_id' => $community->id])->save();
    }

    /**
     * The default Announcements group can't be detached - it's the
     * community's own hub group (also whoever administers it is what
     * makes them a "community admin" in the first place, see
     * isCommunityAdmin()), detaching it would leave the community with
     * no announcement channel at all.
     */
    public function detachGroup(User $user, Community $community, Group $group): void
    {
        if ($group->community_id !== $community->id) {
            throw ValidationException::withMessages(['group_id' => ['This group does not belong to this community.']]);
        }

        if ($group->id === $community->announcement_group_id) {
            throw ValidationException::withMessages(['group_id' => ["The community's Announcements group can't be removed - delete the community instead."]]);
        }

        $canDetach = $this->isCommunityAdmin($user, $community) || $group->owner_id === $user->id;

        if (! $canDetach) {
            throw new AuthorizationException('Only the community owner, a community admin, or the group\'s own owner may remove this group.');
        }

        $group->forceFill(['community_id' => null])->save();
    }

    public function ownedGroupsAvailableToAttach(User $user): Collection
    {
        return Group::where('owner_id', $user->id)
            ->whereNull('community_id')
            ->where('status', '!=', 'deleted')
            ->get();
    }

    public function postAnnouncement(User $user, Community $community, string $title, string $body): GroupAnnouncement
    {
        $this->assertCanManage($user, $community);

        return $this->groupContent->createAnnouncement($community->announcementGroup, $user, $title, $body, false, null);
    }

    /**
     * Deleting the whole community is more severe than attaching a
     * group or posting an announcement, so this stays strictly
     * owner-only (unlike assertCanManage() above) - a soft delete
     * (Community now has SoftDeletes), matching this app's existing
     * restorable-delete convention rather than destroying it outright.
     * Being a soft delete (not a real row DELETE), the groups'
     * community_id FK is untouched - restoring the community later
     * would bring its groups right back with no extra bookkeeping.
     */
    public function deleteCommunity(User $user, Community $community): void
    {
        $this->assertOwner($user, $community);

        $community->delete();
    }

    private function assertCanView(User $user, Community $community): void
    {
        if ($community->owner_id === $user->id) {
            return;
        }

        $isMemberOfAnyGroup = $community->groups()
            ->whereHas('activeMembers', fn ($q) => $q->where('user_id', $user->id))
            ->exists();

        if (! $isMemberOfAnyGroup) {
            throw new AuthorizationException('You are not a member of this community.');
        }
    }

    private function assertOwner(User $user, Community $community): void
    {
        if ($community->owner_id !== $user->id) {
            throw new AuthorizationException('Only the community owner may do this.');
        }
    }

    /**
     * Owner-or-admin gate for attach/announce - less strict than
     * assertOwner() (used only for deleteCommunity()). A Community has
     * no membership table of its own, so "community admin" is defined
     * as: an active member of the community's own Announcements group
     * who holds that group's 'manage_announcements' permission - the
     * same Group role/permission system already used everywhere else
     * (GroupPolicy, GroupRepository::ROLE_PERMISSIONS), reused here
     * rather than inventing a parallel community-membership concept.
     */
    private function assertCanManage(User $user, Community $community): void
    {
        if (! $this->isCommunityAdmin($user, $community)) {
            throw new AuthorizationException('Only the community owner or a community admin may do this.');
        }
    }

    /**
     * Public (unlike assertCanManage()) so the web controller can use
     * it to decide what the show page renders (attach/announce forms,
     * detach buttons) without duplicating this same rule there.
     */
    public function isCommunityAdmin(User $user, Community $community): bool
    {
        if ($community->owner_id === $user->id) {
            return true;
        }

        $announcementGroup = $community->announcementGroup;

        if (! $announcementGroup) {
            return false;
        }

        $membership = $announcementGroup->members()
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->first();

        return $membership?->hasPermission('manage_announcements') ?? false;
    }
}
