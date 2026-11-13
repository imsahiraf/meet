<?php

namespace Tests\Feature\Contacts;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Contacts\Models\ContactRequest;
use Tests\TestCase;

class ContactFlowTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }

    public function test_users_can_search_for_each_other(): void
    {
        $me = $this->activeUser();
        $this->activeUser(['full_name' => 'Findable Person']);

        $this->actingAs($me, 'sanctum')
            ->getJson('/api/v1/users/search?q=Findable')
            ->assertStatus(200)
            ->assertJsonFragment(['full_name' => 'Findable Person']);
    }

    public function test_search_excludes_self(): void
    {
        $me = $this->activeUser(['full_name' => 'SelfSearch Person']);

        $response = $this->actingAs($me, 'sanctum')->getJson('/api/v1/users/search?q=SelfSearch');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_send_and_accept_contact_request_creates_mutual_contact(): void
    {
        $sender = $this->activeUser();
        $receiver = $this->activeUser();

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/v1/contacts/request', ['receiver_id' => $receiver->id])
            ->assertStatus(201);

        $this->assertDatabaseHas('contact_requests', [
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'status' => 'pending',
        ]);

        $requestId = ContactRequest::first()->id;

        $this->actingAs($receiver, 'sanctum')
            ->postJson("/api/v1/contacts/request/{$requestId}/accept")
            ->assertStatus(200);

        $this->assertDatabaseHas('contacts', ['user_id' => $sender->id, 'contact_user_id' => $receiver->id]);
        $this->assertDatabaseHas('contacts', ['user_id' => $receiver->id, 'contact_user_id' => $sender->id]);

        $this->actingAs($sender, 'sanctum')
            ->getJson('/api/v1/contacts')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_cannot_send_duplicate_pending_request(): void
    {
        $sender = $this->activeUser();
        $receiver = $this->activeUser();

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/v1/contacts/request', ['receiver_id' => $receiver->id])
            ->assertStatus(201);

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/v1/contacts/request', ['receiver_id' => $receiver->id])
            ->assertStatus(409);
    }

    public function test_reject_contact_request(): void
    {
        $sender = $this->activeUser();
        $receiver = $this->activeUser();

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/v1/contacts/request', ['receiver_id' => $receiver->id]);

        $requestId = ContactRequest::first()->id;

        $this->actingAs($receiver, 'sanctum')
            ->postJson("/api/v1/contacts/request/{$requestId}/reject")
            ->assertStatus(200);

        $this->assertDatabaseHas('contact_requests', ['id' => $requestId, 'status' => 'rejected']);
        $this->assertDatabaseMissing('contacts', ['user_id' => $sender->id, 'contact_user_id' => $receiver->id]);
    }

    public function test_favorite_and_unfavorite_contact(): void
    {
        [$sender, $receiver] = [$this->activeUser(), $this->activeUser()];
        $this->makeContacts($sender, $receiver);

        $this->actingAs($sender, 'sanctum')
            ->postJson("/api/v1/contacts/{$receiver->id}/favorite")
            ->assertStatus(200);

        $this->assertDatabaseHas('contacts', ['user_id' => $sender->id, 'contact_user_id' => $receiver->id, 'is_favorite' => true]);
        $this->assertDatabaseHas('favorite_contacts', ['user_id' => $sender->id, 'contact_user_id' => $receiver->id]);

        $this->actingAs($sender, 'sanctum')
            ->deleteJson("/api/v1/contacts/{$receiver->id}/favorite")
            ->assertStatus(200);

        $this->assertDatabaseHas('contacts', ['user_id' => $sender->id, 'contact_user_id' => $receiver->id, 'is_favorite' => false]);
    }

    public function test_block_prevents_new_contact_requests_and_rejects_pending_ones(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();

        $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/contacts/request', ['receiver_id' => $b->id])
            ->assertStatus(201);

        $this->actingAs($b, 'sanctum')
            ->postJson("/api/v1/users/{$a->id}/block")
            ->assertStatus(201);

        $this->assertDatabaseHas('contact_requests', ['sender_id' => $a->id, 'receiver_id' => $b->id, 'status' => 'rejected']);

        $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/contacts/request', ['receiver_id' => $b->id])
            ->assertStatus(403);
    }

    public function test_remove_contact(): void
    {
        [$a, $b] = [$this->activeUser(), $this->activeUser()];
        $this->makeContacts($a, $b);

        $this->actingAs($a, 'sanctum')
            ->deleteJson("/api/v1/contacts/{$b->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('contacts', ['user_id' => $a->id, 'contact_user_id' => $b->id]);
    }

    public function test_sender_can_cancel_a_pending_request(): void
    {
        $sender = $this->activeUser();
        $receiver = $this->activeUser();

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/v1/contacts/request', ['receiver_id' => $receiver->id]);

        $requestId = ContactRequest::first()->id;

        $this->actingAs($receiver, 'sanctum')
            ->deleteJson("/api/v1/contacts/request/{$requestId}")
            ->assertStatus(403);

        $this->actingAs($sender, 'sanctum')
            ->deleteJson("/api/v1/contacts/request/{$requestId}")
            ->assertStatus(200);

        $this->assertDatabaseHas('contact_requests', ['id' => $requestId, 'status' => 'cancelled']);
    }

    public function test_lists_pending_requests_for_both_sender_and_receiver(): void
    {
        $sender = $this->activeUser();
        $receiver = $this->activeUser();

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/v1/contacts/request', ['receiver_id' => $receiver->id]);

        $this->actingAs($sender, 'sanctum')
            ->getJson('/api/v1/contacts/requests')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($receiver, 'sanctum')
            ->getJson('/api/v1/contacts/requests')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_lists_blocked_users(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser(['full_name' => 'Blocked Person']);

        $this->actingAs($a, 'sanctum')->postJson("/api/v1/users/{$b->id}/block");

        $this->actingAs($a, 'sanctum')
            ->getJson('/api/v1/users/blocked')
            ->assertStatus(200)
            ->assertJsonFragment(['full_name' => 'Blocked Person']);
    }

    /**
     * Regression test: findPendingRequestBetween()'s second leg used
     * ->orWhere(['sender_id' => $userB, 'receiver_id' => $userA]) which
     * Laravel compiles as (sender_id = ? OR receiver_id = ?) - an OR
     * instead of an AND across those two columns - so ANY unrelated
     * pending request touching either user id matched. A sending C a
     * request made every third party who shared a user id with A or C
     * falsely appear to have a pending request with them.
     */
    public function test_pending_request_between_two_users_does_not_leak_to_an_unrelated_third_party(): void
    {
        $a = $this->activeUser();
        $recipient = $this->activeUser();
        $unrelatedThirdParty = $this->activeUser();

        $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/contacts/request', ['receiver_id' => $recipient->id])
            ->assertStatus(201);

        // The real recipient correctly has a pending request with A...
        $this->actingAs($recipient, 'sanctum')
            ->postJson('/api/v1/contacts/request', ['receiver_id' => $a->id])
            ->assertStatus(409);

        // ...but an unrelated third party sending A a request must not
        // be blocked by A's unrelated pending request to $recipient.
        $this->actingAs($unrelatedThirdParty, 'sanctum')
            ->postJson('/api/v1/contacts/request', ['receiver_id' => $a->id])
            ->assertStatus(201);
    }

    public function test_blocking_does_not_leak_to_an_unrelated_third_party(): void
    {
        $blocker = $this->activeUser();
        $blocked = $this->activeUser();
        $unrelatedThirdParty = $this->activeUser();

        $this->actingAs($blocker, 'sanctum')->postJson("/api/v1/users/{$blocked->id}/block")->assertStatus(201);

        // Sending a request between two users unrelated to the block
        // must not be rejected as though they were blocked too.
        $this->actingAs($unrelatedThirdParty, 'sanctum')
            ->postJson('/api/v1/contacts/request', ['receiver_id' => $blocker->id])
            ->assertStatus(201);
    }

    private function makeContacts(User $a, User $b): void
    {
        $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/contacts/request', ['receiver_id' => $b->id]);

        $requestId = ContactRequest::where('sender_id', $a->id)->where('receiver_id', $b->id)->first()->id;

        $this->actingAs($b, 'sanctum')
            ->postJson("/api/v1/contacts/request/{$requestId}/accept");
    }
}
