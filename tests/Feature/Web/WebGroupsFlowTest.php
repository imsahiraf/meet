<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Groups\Models\Group;
use Tests\TestCase;

class WebGroupsFlowTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }

    public function test_groups_index_and_create_pages_render(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user, 'web')->get('/groups')->assertStatus(200)->assertSee('No groups yet');
        $this->actingAs($user, 'web')->get('/groups/create')->assertStatus(200)->assertSee('Create a group');
    }

    public function test_creating_a_group_via_the_web_form_redirects_to_the_group_page(): void
    {
        $owner = $this->activeUser();
        $member = $this->activeUser(['full_name' => 'Group Member']);

        $response = $this->actingAs($owner, 'web')->post('/groups', [
            'group_name' => 'Book Club',
            'description' => 'Monthly reads',
            'members' => [$member->id],
        ]);

        $group = Group::where('name', 'Book Club')->firstOrFail();
        $response->assertRedirect(route('groups.show', $group));

        $this->actingAs($owner, 'web')
            ->get(route('groups.show', $group))
            ->assertStatus(200)
            ->assertSee('Book Club')
            ->assertSee('Group Member');
    }

    public function test_non_member_gets_403_page_for_group_show(): void
    {
        $owner = $this->activeUser();
        $stranger = $this->activeUser();

        $this->actingAs($owner, 'web')->post('/groups', [
            'group_name' => 'Private Circle',
            'members' => [$this->activeUser()->id],
        ]);
        $group = Group::where('name', 'Private Circle')->firstOrFail();

        $this->actingAs($stranger, 'web')
            ->get(route('groups.show', $group))
            ->assertStatus(403);
    }

    public function test_group_chat_thread_shows_group_name_and_member_count_not_a_single_other_user(): void
    {
        $owner = $this->activeUser();
        $memberA = $this->activeUser();
        $memberB = $this->activeUser();

        $this->actingAs($owner, 'web')->post('/groups', [
            'group_name' => 'Trio Chat',
            'members' => [$memberA->id, $memberB->id],
        ]);
        $group = Group::where('name', 'Trio Chat')->firstOrFail();

        $this->actingAs($owner, 'web')
            ->get(route('chat.show', $group->conversation))
            ->assertStatus(200)
            ->assertSee('Trio Chat')
            ->assertSee('3 members')
            ->assertSee('Group info');
    }

    public function test_owner_can_add_member_via_web_and_member_can_leave(): void
    {
        $owner = $this->activeUser();
        $initialMember = $this->activeUser();
        $newMember = $this->activeUser(['full_name' => 'Newcomer']);

        $this->actingAs($owner, 'web')->post('/groups', [
            'group_name' => 'Growing Group',
            'members' => [$initialMember->id],
        ]);
        $group = Group::where('name', 'Growing Group')->firstOrFail();

        // Simulate the "add member" form: it's driven by a contact search
        // result, so post the underlying user id directly.
        $this->actingAs($owner, 'web')
            ->post(route('groups.members.add', $group), ['user_ids' => [$newMember->id]])
            ->assertRedirect();

        $this->assertDatabaseHas('group_members', ['group_id' => $group->id, 'user_id' => $newMember->id, 'left_at' => null]);

        $this->actingAs($initialMember, 'web')
            ->post(route('groups.leave', $group))
            ->assertRedirect(route('groups.index'));

        $this->assertNotNull($group->fresh()->members()->where('user_id', $initialMember->id)->first()->left_at);
    }
}
