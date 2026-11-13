<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\BroadcastList;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\Message;
use Tests\TestCase;

class WebBroadcastListFlowTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }

    public function test_broadcast_lists_index_and_create_pages_render(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user, 'web')->get('/broadcast-lists')->assertStatus(200)->assertSee('No broadcast lists yet');
        $this->actingAs($user, 'web')->get('/broadcast-lists/create')->assertStatus(200)->assertSee('New broadcast list');
    }

    public function test_creating_a_broadcast_list_via_the_web_form_redirects_to_its_page(): void
    {
        $owner = $this->activeUser();
        $recipientA = $this->activeUser(['full_name' => 'Recipient A']);
        $recipientB = $this->activeUser(['full_name' => 'Recipient B']);

        $response = $this->actingAs($owner, 'web')->post('/broadcast-lists', [
            'name' => 'Close Friends',
            'recipients' => [$recipientA->id, $recipientB->id],
        ]);

        $list = BroadcastList::where('name', 'Close Friends')->firstOrFail();
        $response->assertRedirect(route('broadcast-lists.show', $list));
        $this->assertDatabaseCount('broadcast_list_recipients', 2);

        $this->actingAs($owner, 'web')->get(route('broadcast-lists.show', $list))
            ->assertStatus(200)
            ->assertSee('Recipient A')
            ->assertSee('Recipient B');
    }

    public function test_sending_a_broadcast_message_fans_out_to_separate_private_conversations(): void
    {
        $owner = $this->activeUser();
        $recipientA = $this->activeUser();
        $recipientB = $this->activeUser();

        $this->actingAs($owner, 'web')->post('/broadcast-lists', [
            'name' => 'Announcements',
            'recipients' => [$recipientA->id, $recipientB->id],
        ]);
        $list = BroadcastList::where('name', 'Announcements')->firstOrFail();

        $this->actingAs($owner, 'web')
            ->post(route('broadcast-lists.send', $list), ['body' => 'Big news everyone!'])
            ->assertSessionHas('status', 'Message sent to 2 recipient(s).');

        $conversationA = Conversation::whereHas('members', fn ($q) => $q->where('user_id', $recipientA->id))
            ->whereHas('members', fn ($q) => $q->where('user_id', $owner->id))
            ->firstOrFail();
        $conversationB = Conversation::whereHas('members', fn ($q) => $q->where('user_id', $recipientB->id))
            ->whereHas('members', fn ($q) => $q->where('user_id', $owner->id))
            ->firstOrFail();

        $this->assertNotSame($conversationA->id, $conversationB->id);
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversationA->id, 'body' => 'Big news everyone!']);
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversationB->id, 'body' => 'Big news everyone!']);

        // Recipient B replying must not leak into recipient A's conversation.
        $this->actingAs($recipientB, 'web')->post("/api/v1/messages", [
            'conversation_id' => $conversationB->id,
            'body' => 'Thanks!',
        ])->assertStatus(201);

        $this->assertDatabaseMissing('messages', ['conversation_id' => $conversationA->id, 'body' => 'Thanks!']);
    }

    public function test_a_non_owner_cannot_view_send_to_or_delete_someone_elses_broadcast_list(): void
    {
        $owner = $this->activeUser();
        $recipient = $this->activeUser();
        $stranger = $this->activeUser();

        $this->actingAs($owner, 'web')->post('/broadcast-lists', [
            'name' => 'Private list',
            'recipients' => [$recipient->id],
        ]);
        $list = BroadcastList::where('name', 'Private list')->firstOrFail();

        $this->actingAs($stranger, 'web')->get(route('broadcast-lists.show', $list))->assertStatus(403);
        $this->actingAs($stranger, 'web')->post(route('broadcast-lists.send', $list), ['body' => 'hi'])->assertStatus(403);
        $this->actingAs($stranger, 'web')->delete(route('broadcast-lists.destroy', $list))->assertStatus(403);
    }

    public function test_deleting_a_broadcast_list_does_not_delete_already_sent_messages(): void
    {
        $owner = $this->activeUser();
        $recipient = $this->activeUser();

        $this->actingAs($owner, 'web')->post('/broadcast-lists', [
            'name' => 'Temp list',
            'recipients' => [$recipient->id],
        ]);
        $list = BroadcastList::where('name', 'Temp list')->firstOrFail();

        $this->actingAs($owner, 'web')->post(route('broadcast-lists.send', $list), ['body' => 'keep me']);
        $this->assertDatabaseHas('messages', ['body' => 'keep me']);

        $this->actingAs($owner, 'web')->delete(route('broadcast-lists.destroy', $list))
            ->assertRedirect(route('broadcast-lists.index'));

        $this->assertDatabaseMissing('broadcast_lists', ['id' => $list->id]);
        $this->assertDatabaseHas('messages', ['body' => 'keep me']);
    }
}
