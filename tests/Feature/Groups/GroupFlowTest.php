<?php

namespace Tests\Feature\Groups;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Groups\Models\Group;
use Modules\Groups\Models\GroupRole;
use Tests\TestCase;

class GroupFlowTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }

    private function createGroup(User $owner, array $members): Group
    {
        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/groups', [
            'group_name' => 'Test Group',
            'description' => 'A group for testing',
            'members' => array_map(fn ($u) => $u->id, $members),
        ]);

        $response->assertStatus(201);

        return Group::where('uuid', $response->json('data.uuid'))->firstOrFail();
    }

    public function test_creating_a_group_seeds_roles_members_and_a_conversation(): void
    {
        $owner = $this->activeUser();
        $memberA = $this->activeUser();
        $memberB = $this->activeUser();

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/groups', [
            'group_name' => 'Weekend Trip',
            'members' => [$memberA->id, $memberB->id],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Weekend Trip')
            ->assertJsonPath('data.owner_id', $owner->id)
            ->assertJsonPath('data.member_count', 3);

        $group = Group::where('name', 'Weekend Trip')->firstOrFail();

        $this->assertNotNull($group->conversation);
        $this->assertSame('group', $group->conversation->type);
        $this->assertDatabaseCount('group_roles', 4);
        $this->assertDatabaseHas('group_members', ['group_id' => $group->id, 'user_id' => $owner->id]);
        $this->assertDatabaseHas('conversation_members', ['conversation_id' => $group->conversation_id, 'user_id' => $memberA->id]);
    }

    public function test_non_member_cannot_view_group(): void
    {
        $owner = $this->activeUser();
        $stranger = $this->activeUser();
        $group = $this->createGroup($owner, [$this->activeUser()]);

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/groups/{$group->id}")
            ->assertStatus(403);
    }

    public function test_owner_can_add_and_remove_members(): void
    {
        $owner = $this->activeUser();
        $newMember = $this->activeUser();
        $group = $this->createGroup($owner, [$this->activeUser()]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/groups/{$group->id}/members", ['user_ids' => [$newMember->id]])
            ->assertStatus(201);

        $this->assertDatabaseHas('group_members', ['group_id' => $group->id, 'user_id' => $newMember->id, 'left_at' => null]);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/groups/{$group->id}/members/{$newMember->id}")
            ->assertStatus(200);

        $this->assertDatabaseHas('group_members', ['group_id' => $group->id, 'user_id' => $newMember->id]);
        $this->assertNotNull($group->fresh()->members()->where('user_id', $newMember->id)->first()->left_at);
    }

    public function test_adding_a_duplicate_member_is_rejected(): void
    {
        $owner = $this->activeUser();
        $existing = $this->activeUser();
        $group = $this->createGroup($owner, [$existing]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/groups/{$group->id}/members", ['user_ids' => [$existing->id]])
            ->assertStatus(409);
    }

    public function test_member_without_permission_cannot_add_members(): void
    {
        $owner = $this->activeUser();
        $member = $this->activeUser();
        $group = $this->createGroup($owner, [$member]);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/v1/groups/{$group->id}/members", ['user_ids' => [$this->activeUser()->id]])
            ->assertStatus(403);
    }

    public function test_owner_cannot_be_removed(): void
    {
        $owner = $this->activeUser();
        $admin = $this->activeUser();
        $group = $this->createGroup($owner, [$admin]);

        $adminRole = GroupRole::where('group_id', $group->id)->where('role_name', GroupRole::ADMIN)->first();
        $adminMember = $group->members()->where('user_id', $admin->id)->first();
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/groups/{$group->id}/roles", [
            'member_id' => $adminMember->id,
            'role_id' => $adminRole->id,
        ])->assertStatus(200);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/groups/{$group->id}/members/{$owner->id}")
            ->assertStatus(403);
    }

    public function test_owner_must_transfer_ownership_before_leaving(): void
    {
        $owner = $this->activeUser();
        $member = $this->activeUser();
        $group = $this->createGroup($owner, [$member]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/groups/{$group->id}/leave")
            ->assertStatus(409);

        $this->assertSame($owner->id, $group->fresh()->owner_id);
    }

    public function test_transfer_ownership_promotes_new_owner_and_demotes_old_owner(): void
    {
        $owner = $this->activeUser();
        $member = $this->activeUser();
        $group = $this->createGroup($owner, [$member]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/groups/{$group->id}/transfer-ownership", ['user_id' => $member->id])
            ->assertStatus(200);

        $group->refresh();
        $this->assertSame($member->id, $group->owner_id);

        $newOwnerMember = $group->members()->where('user_id', $member->id)->first();
        $oldOwnerMember = $group->members()->where('user_id', $owner->id)->first();
        $this->assertSame(GroupRole::OWNER, $newOwnerMember->role->role_name);
        $this->assertSame(GroupRole::ADMIN, $oldOwnerMember->role->role_name);

        // Old owner (now Admin) can leave without a transfer.
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/groups/{$group->id}/leave")
            ->assertStatus(200);
    }

    public function test_group_member_limit_is_enforced(): void
    {
        $owner = $this->activeUser();
        $group = $this->createGroup($owner, [$this->activeUser()]);
        $group->update(['member_limit' => 2]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/groups/{$group->id}/members", ['user_ids' => [$this->activeUser()->id]])
            ->assertStatus(422);
    }

    public function test_admin_only_edit_info_toggle_blocks_plain_members(): void
    {
        $owner = $this->activeUser();
        $member = $this->activeUser();
        $group = $this->createGroup($owner, [$member]);
        $group->update(['admin_only_edit_info' => true]);

        $this->actingAs($member, 'sanctum')
            ->putJson("/api/v1/groups/{$group->id}", ['name' => 'Renamed'])
            ->assertStatus(403);

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/groups/{$group->id}", ['name' => 'Renamed'])
            ->assertStatus(200);
    }

    public function test_invite_link_lets_a_new_user_join(): void
    {
        $owner = $this->activeUser();
        $group = $this->createGroup($owner, [$this->activeUser()]);
        $joiner = $this->activeUser();

        $invite = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/groups/{$group->id}/invite")
            ->assertStatus(201)
            ->json('data');

        $this->actingAs($joiner, 'sanctum')
            ->postJson('/api/v1/groups/join', ['invite_token' => $invite['invite_token']])
            ->assertStatus(200);

        $this->assertDatabaseHas('group_members', ['group_id' => $group->id, 'user_id' => $joiner->id]);
    }

    public function test_join_request_flow_for_approval_required_group(): void
    {
        $owner = $this->activeUser();
        $group = $this->createGroup($owner, [$this->activeUser()]);
        $group->update(['approval_required' => true]);
        $requester = $this->activeUser();

        $this->actingAs($requester, 'sanctum')
            ->postJson("/api/v1/groups/{$group->id}/join-request", ['message' => 'Please let me in'])
            ->assertStatus(201);

        $joinRequest = $group->joinRequests()->where('user_id', $requester->id)->firstOrFail();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/groups/{$group->id}/join-request/{$joinRequest->id}/approve")
            ->assertStatus(200);

        $this->assertDatabaseHas('group_members', ['group_id' => $group->id, 'user_id' => $requester->id]);
    }

    public function test_announcement_lifecycle(): void
    {
        $owner = $this->activeUser();
        $group = $this->createGroup($owner, [$this->activeUser()]);

        $created = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/groups/{$group->id}/announcements", [
            'title' => 'Welcome',
            'announcement' => 'Please read the group rules.',
        ])->assertStatus(201)->json('data');

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/groups/{$group->id}/announcements")
            ->assertStatus(200)
            ->assertJsonFragment(['title' => 'Welcome']);

        $announcementId = \Modules\Groups\Models\GroupAnnouncement::where('uuid', $created['uuid'])->firstOrFail()->id;

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/groups/{$group->id}/announcements/{$announcementId}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('group_announcements', ['id' => $announcementId]);
    }

    public function test_poll_create_vote_and_close(): void
    {
        $owner = $this->activeUser();
        $voter = $this->activeUser();
        $group = $this->createGroup($owner, [$voter]);

        $poll = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/groups/{$group->id}/polls", [
            'question' => 'Where should we go?',
            'options' => ['Beach', 'Mountains'],
        ])->assertStatus(201)->json('data');

        $beachOptionId = $poll['options'][0]['id'];
        $pollModel = \Modules\Groups\Models\GroupPoll::where('uuid', $poll['uuid'])->firstOrFail();

        $this->actingAs($voter, 'sanctum')
            ->postJson("/api/v1/groups/{$group->id}/polls/{$pollModel->id}/vote", ['option_ids' => [$beachOptionId]])
            ->assertStatus(200)
            ->assertJsonPath('data.total_votes', 1);

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/groups/{$group->id}/polls/{$pollModel->id}/close")
            ->assertStatus(200)
            ->assertJsonPath('data.is_closed', true);
    }

    public function test_event_lifecycle(): void
    {
        $owner = $this->activeUser();
        $group = $this->createGroup($owner, [$this->activeUser()]);

        $event = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/groups/{$group->id}/events", [
            'title' => 'Kickoff Meetup',
            'event_start' => now()->addDay()->toDateTimeString(),
        ])->assertStatus(201)->json('data');

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/groups/{$group->id}/events")
            ->assertStatus(200)
            ->assertJsonFragment(['title' => 'Kickoff Meetup']);
    }

    public function test_export_returns_csv(): void
    {
        $owner = $this->activeUser();
        $group = $this->createGroup($owner, [$this->activeUser()]);

        $response = $this->actingAs($owner, 'sanctum')->get("/api/v1/groups/{$group->id}/export");

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString($group->name, $response->getContent());
    }
}
