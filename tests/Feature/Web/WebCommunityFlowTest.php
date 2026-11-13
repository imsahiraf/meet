<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Groups\Models\Community;
use Modules\Groups\Models\Group;
use Modules\Groups\Models\GroupMember;
use Modules\Groups\Models\GroupRole;
use Modules\Groups\Repositories\GroupRepository;
use Modules\Groups\Services\GroupService;
use Tests\TestCase;

class WebCommunityFlowTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }

    public function test_communities_index_and_create_pages_render(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user, 'web')->get('/communities')->assertStatus(200)->assertSee('No communities yet');
        $this->actingAs($user, 'web')->get('/communities/create')->assertStatus(200)->assertSee('Create a community');
    }

    public function test_creating_a_community_auto_creates_an_owner_only_announcements_group(): void
    {
        $owner = $this->activeUser();

        $response = $this->actingAs($owner, 'web')->post('/communities', [
            'name' => 'Neighborhood Watch',
            'description' => 'Keeping an eye out',
        ]);

        $community = Community::where('name', 'Neighborhood Watch')->firstOrFail();
        $response->assertRedirect(route('communities.show', $community));

        $this->assertNotNull($community->announcement_group_id);
        $announcementsGroup = Group::find($community->announcement_group_id);
        $this->assertSame('Announcements', $announcementsGroup->name);
        $this->assertSame($community->id, $announcementsGroup->community_id);
        $this->assertTrue((bool) $announcementsGroup->admin_only_messaging);
        $this->assertSame(1, $announcementsGroup->activeMembers()->count());
        $this->assertSame($owner->id, $announcementsGroup->owner_id);
    }

    public function test_owner_can_add_an_owned_group_to_their_community_but_not_a_group_they_dont_own(): void
    {
        $owner = $this->activeUser();
        $member = $this->activeUser();
        $stranger = $this->activeUser();

        $this->actingAs($owner, 'web')->post('/communities', ['name' => 'My Community']);
        $community = Community::where('name', 'My Community')->firstOrFail();

        $ownedGroup = app(GroupService::class)->create($owner, 'My Own Group', null, null, [$member->id]);
        $strangersGroup = app(GroupService::class)->create($stranger, 'Not Mine', null, null, [$member->id]);

        $this->actingAs($owner, 'web')
            ->post(route('communities.groups.attach', $community), ['group_id' => $ownedGroup->id])
            ->assertRedirect();
        $this->assertSame($community->id, $ownedGroup->fresh()->community_id);

        $this->actingAs($owner, 'web')
            ->post(route('communities.groups.attach', $community), ['group_id' => $strangersGroup->id])
            ->assertStatus(403);
        $this->assertNull($strangersGroup->fresh()->community_id);
    }

    public function test_a_group_cannot_be_added_to_two_communities(): void
    {
        $owner = $this->activeUser();
        $member = $this->activeUser();

        $this->actingAs($owner, 'web')->post('/communities', ['name' => 'Community A']);
        $communityA = Community::where('name', 'Community A')->firstOrFail();
        $this->actingAs($owner, 'web')->post('/communities', ['name' => 'Community B']);
        $communityB = Community::where('name', 'Community B')->firstOrFail();

        $group = app(GroupService::class)->create($owner, 'Shared Group', null, null, [$member->id]);

        $this->actingAs($owner, 'web')->post(route('communities.groups.attach', $communityA), ['group_id' => $group->id])->assertRedirect();

        $this->actingAs($owner, 'web')
            ->post(route('communities.groups.attach', $communityB), ['group_id' => $group->id])
            ->assertSessionHasErrors();
    }

    public function test_owner_can_post_a_community_announcement_and_a_member_of_an_attached_group_can_see_it(): void
    {
        $owner = $this->activeUser();
        $member = $this->activeUser();

        $this->actingAs($owner, 'web')->post('/communities', ['name' => 'Announce Test']);
        $community = Community::where('name', 'Announce Test')->firstOrFail();

        $group = app(GroupService::class)->create($owner, 'Member Group', null, null, [$member->id]);
        $this->actingAs($owner, 'web')->post(route('communities.groups.attach', $community), ['group_id' => $group->id]);

        $this->actingAs($owner, 'web')->post(route('communities.announcements.store', $community), [
            'title' => 'Welcome',
            'announcement' => 'Glad to have you here.',
        ])->assertRedirect();

        $this->actingAs($member, 'web')->get(route('communities.show', $community))
            ->assertStatus(200)
            ->assertSee('Welcome')
            ->assertSee('Glad to have you here.');
    }

    public function test_a_non_member_stranger_cannot_view_the_community(): void
    {
        $owner = $this->activeUser();
        $stranger = $this->activeUser();

        $this->actingAs($owner, 'web')->post('/communities', ['name' => 'Private Community']);
        $community = Community::where('name', 'Private Community')->firstOrFail();

        $this->actingAs($stranger, 'web')->get(route('communities.show', $community))->assertStatus(403);
    }

    /**
     * Promotes $user to the Admin role of the community's own
     * Announcements group - the mechanism CommunityService::
     * isCommunityAdmin() actually checks, since a Community has no
     * membership table of its own.
     */
    private function makeCommunityAdmin(Community $community, User $user): void
    {
        $announcementsGroup = Group::find($community->announcement_group_id);
        app(GroupService::class)->addMembers($announcementsGroup, $community->owner, [$user->id]);

        $member = GroupMember::where('group_id', $announcementsGroup->id)->where('user_id', $user->id)->firstOrFail();
        $adminRole = app(GroupRepository::class)->findRole($announcementsGroup->id, GroupRole::ADMIN);

        app(GroupService::class)->assignRole($announcementsGroup, $community->owner, $member->id, $adminRole->id);
    }

    public function test_a_community_admin_not_the_owner_can_attach_a_group_and_post_an_announcement(): void
    {
        $owner = $this->activeUser();
        $admin = $this->activeUser();

        $this->actingAs($owner, 'web')->post('/communities', ['name' => 'Delegated Community']);
        $community = Community::where('name', 'Delegated Community')->firstOrFail()->fresh(['owner']);
        $this->makeCommunityAdmin($community, $admin);

        $adminsOwnGroup = app(GroupService::class)->create($admin, 'Admin Owned Group', null, null, [$this->activeUser()->id]);

        $this->actingAs($admin, 'web')
            ->post(route('communities.groups.attach', $community), ['group_id' => $adminsOwnGroup->id])
            ->assertRedirect();
        $this->assertSame($community->id, $adminsOwnGroup->fresh()->community_id);

        $this->actingAs($admin, 'web')->post(route('communities.announcements.store', $community), [
            'title' => 'From the admin',
            'announcement' => 'Delegated posting works.',
        ])->assertRedirect();

        $this->assertDatabaseHas('group_announcements', ['title' => 'From the admin']);
    }

    public function test_a_regular_member_who_is_not_a_community_admin_cannot_attach_or_announce(): void
    {
        $owner = $this->activeUser();
        $member = $this->activeUser();

        $this->actingAs($owner, 'web')->post('/communities', ['name' => 'Locked Down']);
        $community = Community::where('name', 'Locked Down')->firstOrFail()->fresh(['owner']);

        $announcementsGroup = Group::find($community->announcement_group_id);
        app(GroupService::class)->addMembers($announcementsGroup, $owner, [$member->id]);

        $memberOwnedGroup = app(GroupService::class)->create($member, 'Regular Member Group', null, null, [$this->activeUser()->id]);

        $this->actingAs($member, 'web')
            ->post(route('communities.groups.attach', $community), ['group_id' => $memberOwnedGroup->id])
            ->assertStatus(403);
        $this->assertNull($memberOwnedGroup->fresh()->community_id);

        $this->actingAs($member, 'web')->post(route('communities.announcements.store', $community), [
            'title' => 'Not allowed',
            'announcement' => 'Should be blocked.',
        ])->assertStatus(403);
    }

    public function test_owner_can_detach_a_group_and_a_later_group_owner_can_detach_it_too(): void
    {
        $owner = $this->activeUser();
        $admin = $this->activeUser();
        $laterGroupOwner = $this->activeUser();

        $this->actingAs($owner, 'web')->post('/communities', ['name' => 'Detach Test']);
        $community = Community::where('name', 'Detach Test')->firstOrFail()->fresh(['owner']);
        $this->makeCommunityAdmin($community, $admin);

        // Attaching always requires the attacher to both manage the
        // community AND own the group being attached - so the admin
        // (not the owner) attaches their own group here, to also cover
        // the owner-can-detach-anything path below with a group they
        // didn't personally attach.
        $group = app(GroupService::class)->create($admin, 'Detachable Group', null, null, [$this->activeUser()->id]);
        $this->actingAs($admin, 'web')->post(route('communities.groups.attach', $community), ['group_id' => $group->id]);
        $this->assertSame($community->id, $group->fresh()->community_id);

        // The community owner can detach any group, even one they
        // didn't attach themselves.
        $this->actingAs($owner, 'web')
            ->delete(route('communities.groups.detach', [$community, $group]))
            ->assertRedirect();
        $this->assertNull($group->fresh()->community_id);

        // Re-attach, then simulate the group's ownership changing
        // hands (e.g. via transfer-ownership) to someone who is
        // neither the community owner nor a community admin - they
        // must still be able to detach their own group.
        $this->actingAs($admin, 'web')->post(route('communities.groups.attach', $community), ['group_id' => $group->id]);
        $group->forceFill(['owner_id' => $laterGroupOwner->id])->save();

        $this->actingAs($laterGroupOwner, 'web')
            ->delete(route('communities.groups.detach', [$community, $group]))
            ->assertRedirect();
        $this->assertNull($group->fresh()->community_id);
    }

    public function test_the_default_announcements_group_cannot_be_detached(): void
    {
        $owner = $this->activeUser();

        $this->actingAs($owner, 'web')->post('/communities', ['name' => 'Protected Announcements']);
        $community = Community::where('name', 'Protected Announcements')->firstOrFail();
        $announcementsGroup = Group::find($community->announcement_group_id);

        $this->actingAs($owner, 'web')
            ->delete(route('communities.groups.detach', [$community, $announcementsGroup]))
            ->assertSessionHasErrors();
        $this->assertSame($community->id, $announcementsGroup->fresh()->community_id);
    }

    public function test_a_stranger_cannot_detach_someone_elses_group_from_a_community(): void
    {
        $owner = $this->activeUser();
        $stranger = $this->activeUser();

        $this->actingAs($owner, 'web')->post('/communities', ['name' => 'Guarded Community']);
        $community = Community::where('name', 'Guarded Community')->firstOrFail();

        $group = app(GroupService::class)->create($owner, 'Owners Own Group', null, null, [$this->activeUser()->id]);
        $this->actingAs($owner, 'web')->post(route('communities.groups.attach', $community), ['group_id' => $group->id]);
        $this->assertSame($community->id, $group->fresh()->community_id);

        $this->actingAs($stranger, 'web')
            ->delete(route('communities.groups.detach', [$community, $group]))
            ->assertStatus(403);
        $this->assertSame($community->id, $group->fresh()->community_id);
    }

    public function test_only_the_owner_can_delete_the_community_and_it_is_a_soft_delete(): void
    {
        $owner = $this->activeUser();
        $admin = $this->activeUser();

        $this->actingAs($owner, 'web')->post('/communities', ['name' => 'Deletable']);
        $community = Community::where('name', 'Deletable')->firstOrFail()->fresh(['owner']);
        $this->makeCommunityAdmin($community, $admin);

        // A community admin who is not the owner cannot delete it.
        $this->actingAs($admin, 'web')->delete(route('communities.destroy', $community))->assertStatus(403);
        $this->assertNull($community->fresh()->deleted_at);

        $this->actingAs($owner, 'web')->delete(route('communities.destroy', $community))->assertRedirect(route('communities.index'));

        $this->assertSoftDeleted('communities', ['id' => $community->id]);
        $this->actingAs($owner, 'web')->get(route('communities.show', $community))->assertStatus(404);
    }
}
