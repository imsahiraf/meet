<?php

namespace Tests\Feature\Chat;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\ConversationMember;
use Modules\Chat\Models\Message;
use Modules\Admin\Models\Setting;
use Modules\Contacts\Models\BlockedUser;
use Tests\TestCase;

class ChatFlowTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }

    private function startConversation(User $a, User $b): int
    {
        $response = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/conversations', ['participant_id' => $b->id]);

        return Conversation::where('uuid', $response->json('data.uuid'))->first()->id;
    }

    public function test_starting_conversation_reuses_existing_private_conversation(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();

        $first = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/conversations', ['participant_id' => $b->id]);
        $first->assertStatus(201);

        $second = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/conversations', ['participant_id' => $b->id]);
        $second->assertStatus(201);

        $this->assertSame($first->json('data.uuid'), $second->json('data.uuid'));
        $this->assertDatabaseCount('conversations', 1);
    }

    /**
     * A meeting's own room chat is a real 'meeting'-type Conversation
     * with only the organizer as a member until someone else actually
     * joins (see MeetingService::createMeetingChat()/addConversationMember()).
     * If nobody ever joins - an instant meeting nobody else showed up
     * to, for example - that single-member, zero-message conversation
     * must never leak into the regular chat list, where it would
     * render as "Unknown" / "?" / "No messages yet" since there's no
     * second member to resolve a name from.
     */
    public function test_a_solo_meeting_chat_with_no_other_member_is_excluded_from_the_conversation_list(): void
    {
        $organizer = $this->activeUser();

        $meetingConversation = Conversation::create([
            'type' => 'meeting',
            'title' => 'Instant Meeting',
            'created_by' => $organizer->id,
            'last_activity_at' => now(),
        ]);
        ConversationMember::create(['conversation_id' => $meetingConversation->id, 'user_id' => $organizer->id, 'role' => 'member']);

        $other = $this->activeUser();
        $this->startConversation($organizer, $other);

        $response = $this->actingAs($organizer, 'sanctum')->getJson('/api/v1/conversations');

        $response->assertStatus(200);
        $uuids = collect($response->json('data'))->pluck('uuid');
        $this->assertNotContains($meetingConversation->uuid, $uuids);
        $this->assertCount(1, $uuids);
    }

    public function test_blocked_users_cannot_start_a_conversation(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();

        BlockedUser::create(['user_id' => $b->id, 'blocked_user_id' => $a->id]);

        $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/conversations', ['participant_id' => $b->id])
            ->assertStatus(403);
    }

    public function test_send_and_list_messages_with_delivery_tracking(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $send = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'Hello there']);
        $send->assertStatus(201)->assertJsonPath('data.body', 'Hello there');

        $this->assertDatabaseHas('message_status', ['user_id' => $b->id, 'status' => 'sent']);

        $list = $this->actingAs($b, 'sanctum')->getJson("/api/v1/messages/{$conversationId}");
        $list->assertStatus(200)->assertJsonCount(1, 'data');
    }

    /**
     * A push notification reaching a fully backgrounded/closed app is
     * itself real evidence the message arrived - the service worker's
     * own push handler calls this (see public/service-worker.js) with
     * no page open at all, purely for the delivery side effect,
     * without needing to actually poll/list messages the way every
     * other path to "delivered" already does.
     */
    public function test_the_service_worker_can_mark_a_message_delivered_without_listing_messages(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $stranger = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'Are you there?']);

        $this->assertDatabaseHas('message_status', ['user_id' => $b->id, 'status' => 'sent', 'delivered_at' => null]);

        $this->actingAs($b, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversationId}/delivered")
            ->assertStatus(200);

        $status = \Modules\Chat\Models\MessageStatus::where('user_id', $b->id)->first();
        $this->assertSame('delivered', $status->status);
        $this->assertNotNull($status->delivered_at);

        // Not a conversation member - must not be able to affect its
        // delivery state at all.
        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversationId}/delivered")
            ->assertStatus(403);
    }

    /**
     * Regression test: MessageResource used to omit 'id' and
     * 'sender_id', which the chat thread's JS relies on for tracking
     * "last seen message" (polling would otherwise re-fetch and
     * re-append the entire history every cycle) and for telling own
     * vs. received messages apart.
     */
    public function test_message_resource_exposes_id_and_sender_id_for_the_polling_client(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $send = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'Hello there']);

        $send->assertStatus(201)
            ->assertJsonPath('data.sender_id', $a->id)
            ->assertJsonStructure(['data' => ['id']]);
        $this->assertIsInt($send->json('data.id'));

        $list = $this->actingAs($b, 'sanctum')->getJson("/api/v1/messages/{$conversationId}");
        $list->assertJsonPath('data.0.sender_id', $a->id);
        $this->assertIsInt($list->json('data.0.id'));
    }

    public function test_non_member_cannot_send_or_list_messages(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $stranger = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($stranger, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'Hi'])
            ->assertStatus(403);

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/messages/{$conversationId}")
            ->assertStatus(403);
    }

    public function test_edit_message_within_window(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'Oops typo']);
        $messageId = Message::first()->id;

        $this->actingAs($a, 'sanctum')
            ->patchJson("/api/v1/messages/{$messageId}", ['body' => 'Fixed message'])
            ->assertStatus(200)
            ->assertJsonPath('data.body', 'Fixed message');

        $this->actingAs($b, 'sanctum')
            ->patchJson("/api/v1/messages/{$messageId}", ['body' => 'Hijacked'])
            ->assertStatus(403);
    }

    public function test_delete_for_me_hides_message_only_for_that_user(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'Delete me for A only']);
        $messageId = Message::first()->id;

        $this->actingAs($a, 'sanctum')
            ->deleteJson("/api/v1/messages/{$messageId}", ['mode' => 'me'])
            ->assertStatus(200);

        $aList = $this->actingAs($a, 'sanctum')->getJson("/api/v1/messages/{$conversationId}");
        $aList->assertJsonCount(0, 'data');

        $bList = $this->actingAs($b, 'sanctum')->getJson("/api/v1/messages/{$conversationId}");
        $bList->assertJsonCount(1, 'data');
    }

    public function test_delete_for_everyone_removes_message_for_all(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'Delete for everyone']);
        $messageId = Message::first()->id;

        $this->actingAs($a, 'sanctum')
            ->deleteJson("/api/v1/messages/{$messageId}", ['mode' => 'everyone'])
            ->assertStatus(200);

        $this->assertSoftDeleted('messages', ['id' => $messageId]);
    }

    public function test_delete_for_everyone_window_is_admin_configurable(): void
    {
        Setting::updateOrCreate(
            ['setting_key' => 'delete_for_everyone_window_minutes'],
            ['setting_value' => '1', 'category' => 'chat', 'data_type' => 'integer', 'is_editable' => true],
        );

        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'Shrunk window']);
        $messageId = Message::first()->id;

        $this->travel(2)->minutes();

        $this->actingAs($a, 'sanctum')
            ->deleteJson("/api/v1/messages/{$messageId}", ['mode' => 'everyone'])
            ->assertStatus(403);

        $this->assertDatabaseHas('messages', ['id' => $messageId, 'deleted_at' => null]);
    }

    public function test_react_and_remove_reaction(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'React to this']);
        $messageId = Message::first()->id;

        $this->actingAs($b, 'sanctum')
            ->postJson("/api/v1/messages/{$messageId}/reaction", ['emoji' => '👍'])
            ->assertStatus(200);

        $this->assertDatabaseHas('message_reactions', ['message_id' => $messageId, 'user_id' => $b->id, 'emoji' => '👍']);

        $this->actingAs($b, 'sanctum')
            ->deleteJson("/api/v1/messages/{$messageId}/reaction")
            ->assertStatus(200);

        $this->assertDatabaseMissing('message_reactions', ['message_id' => $messageId, 'user_id' => $b->id]);
    }

    public function test_reply_to_message_creates_preview_snapshot(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'Original message']);
        $originalId = Message::first()->id;

        $reply = $this->actingAs($b, 'sanctum')
            ->postJson("/api/v1/messages/{$originalId}/reply", ['body' => 'Replying to you']);

        $reply->assertStatus(201)->assertJsonPath('data.reply_to.preview', 'Original message');
    }

    public function test_forward_message_to_another_conversation(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $c = $this->activeUser();
        $convoAB = $this->startConversation($a, $b);
        $convoAC = $this->startConversation($a, $c);

        $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $convoAB, 'body' => 'Forward this']);
        $originalId = Message::first()->id;

        $forward = $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/messages/{$originalId}/forward", ['conversation_id' => $convoAC]);

        $forward->assertStatus(201)->assertJsonPath('data.body', 'Forward this');
        $this->assertDatabaseHas('forwarded_messages', ['original_message_id' => $originalId]);
    }

    public function test_star_and_unstar_message(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'Star me']);
        $messageId = Message::first()->id;

        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/messages/{$messageId}/star")
            ->assertStatus(200)
            ->assertJsonPath('data.is_starred', true);

        $this->actingAs($a, 'sanctum')
            ->getJson('/api/v1/messages/starred')
            ->assertJsonCount(1, 'data');

        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/messages/{$messageId}/star")
            ->assertJsonPath('data.is_starred', false);
    }

    /**
     * Real production bug: "delete for everyone" only ever soft-deletes
     * the message (a tombstone, so the starred_messages/pinned_messages
     * row's cascadeOnDelete never fires - the message row still exists).
     * But Message::SoftDeletes' default query scope hides that message
     * from every relationship lookup, so the starred/pinned list's
     * `$entry->message` came back null and crashed the "Starred
     * messages" page outright (ViewException: "Attempt to read property
     * 'id' on null"). The list must silently exclude a since-deleted
     * message instead of choking on it.
     */
    public function test_starred_and_pinned_lists_exclude_a_message_deleted_for_everyone(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'Star then delete me']);
        $messageId = Message::first()->id;

        $this->actingAs($a, 'sanctum')->postJson("/api/v1/messages/{$messageId}/star")->assertStatus(200);
        $this->actingAs($a, 'sanctum')->postJson("/api/v1/messages/{$messageId}/pin")->assertStatus(200);

        $this->actingAs($a, 'sanctum')
            ->deleteJson("/api/v1/messages/{$messageId}", ['mode' => 'everyone'])
            ->assertStatus(200);
        $this->assertSoftDeleted('messages', ['id' => $messageId]);

        // Deleting for everyone now explicitly strips the message from
        // both tables first, rather than leaving them orphaned (still
        // pointing at a now-tombstoned message) and merely hiding that at
        // read time - the whereHas() guard in listStarred()/listPinned()
        // is a defence for OLDER data (e.g. an expired disappearing
        // message purged outright), not a substitute for this cleanup.
        $this->assertDatabaseMissing('starred_messages', ['message_id' => $messageId]);
        $this->assertDatabaseMissing('pinned_messages', ['message_id' => $messageId]);

        $this->actingAs($a, 'sanctum')
            ->getJson('/api/v1/messages/starred')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->actingAs($a, 'sanctum')
            ->get(route('chat.starred'))
            ->assertOk()
            ->assertDontSee('Star then delete me');
    }

    /**
     * WhatsApp-style: only one message can be pinned per chat at a time -
     * pinning a new one silently replaces whichever was pinned before,
     * rather than erroring once some cap is reached.
     */
    public function test_pinning_a_new_message_replaces_the_previously_pinned_one(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'First']);
        $first = Message::first()->id;

        $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'Second']);
        $second = Message::orderByDesc('id')->first()->id;

        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/messages/{$first}/pin")
            ->assertJsonPath('data.is_pinned', true);

        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/messages/{$second}/pin")
            ->assertJsonPath('data.is_pinned', true);

        $pinned = $this->actingAs($a, 'sanctum')
            ->getJson("/api/v1/conversations/{$conversationId}/pinned")
            ->json('data');

        $this->assertCount(1, $pinned, 'only the most recently pinned message should still be pinned');
        $this->assertSame($second, $pinned[0]['id']);
    }

    /**
     * Reproduces the real bug report: the toggle endpoint itself
     * returning the right `is_starred`/`is_pinned` flag is not the same
     * thing as the state surviving a reload - a reload re-fetches the
     * thread via GET /messages/{conversation} (MessageResource), which
     * is what the frontend actually renders from.
     */
    public function test_star_and_pin_persist_after_reload(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'Persist me']);
        $messageId = Message::first()->id;

        $this->actingAs($a, 'sanctum')->postJson("/api/v1/messages/{$messageId}/star")->assertJsonPath('data.is_starred', true);
        $this->actingAs($a, 'sanctum')->postJson("/api/v1/messages/{$messageId}/pin")->assertJsonPath('data.is_pinned', true);

        // Simulates a fresh page reload re-fetching the thread from scratch.
        $reloaded = $this->actingAs($a, 'sanctum')->getJson('/api/v1/messages/' . $conversationId);
        $reloaded->assertStatus(200);

        $message = collect($reloaded->json('data'))->firstWhere('id', $messageId);
        $this->assertNotNull($message);
        $this->assertTrue($message['is_starred'], 'Star did not survive a reload.');
        $this->assertArrayHasKey('is_pinned', $message, 'MessageResource never returns is_pinned at all.');
        $this->assertTrue($message['is_pinned'] ?? false, 'Pin did not survive a reload.');
    }

    public function test_mark_conversation_read_clears_unread_count(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'Unread for B']);

        $before = $this->actingAs($b, 'sanctum')->getJson('/api/v1/conversations');
        $this->assertSame(1, $before->json('data.0.unread_count'));

        $this->actingAs($b, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversationId}/read")
            ->assertStatus(200);

        $after = $this->actingAs($b, 'sanctum')->getJson('/api/v1/conversations');
        $this->assertSame(0, $after->json('data.0.unread_count'));
    }

    public function test_total_unread_count_sums_across_every_conversation(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $c = $this->activeUser();
        $conversationAB = $this->startConversation($a, $b);
        $conversationAC = $this->startConversation($a, $c);

        $this->actingAs($b, 'sanctum')->postJson('/api/v1/messages', ['conversation_id' => $conversationAB, 'body' => 'from b']);
        $this->actingAs($c, 'sanctum')->postJson('/api/v1/messages', ['conversation_id' => $conversationAC, 'body' => 'from c 1']);
        $this->actingAs($c, 'sanctum')->postJson('/api/v1/messages', ['conversation_id' => $conversationAC, 'body' => 'from c 2']);

        $this->actingAs($a, 'sanctum')->getJson('/api/v1/conversations/unread-count')
            ->assertStatus(200)
            ->assertJsonPath('data.total', 3);

        $this->actingAs($a, 'sanctum')->postJson("/api/v1/conversations/{$conversationAC}/read")->assertStatus(200);

        $this->actingAs($a, 'sanctum')->getJson('/api/v1/conversations/unread-count')
            ->assertJsonPath('data.total', 1);
    }

    public function test_conversation_list_includes_the_other_member(): void
    {
        $a = $this->activeUser(['full_name' => 'Alice Example']);
        $b = $this->activeUser(['full_name' => 'Bob Example']);
        $this->startConversation($a, $b);

        $list = $this->actingAs($a, 'sanctum')->getJson('/api/v1/conversations');
        $this->assertSame('Bob Example', $list->json('data.0.other_user.full_name'));

        $listForB = $this->actingAs($b, 'sanctum')->getJson('/api/v1/conversations');
        $this->assertSame('Alice Example', $listForB->json('data.0.other_user.full_name'));
    }

    /**
     * Real bug fix: ConversationResource's other_user.presence_status
     * used to ignore last_seen_visibility entirely (unlike
     * ContactResource, which already gated it) - a user set to
     * "nobody" still leaked their live presence via the chat API.
     */
    public function test_conversation_list_hides_presence_status_when_last_seen_visibility_is_nobody(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $this->startConversation($a, $b);

        \Modules\User\Models\UserPrivacy::updateOrCreate(['user_id' => $b->id], ['last_seen_visibility' => 'nobody']);
        \Modules\User\Models\UserPresence::updateOrCreate(['user_id' => $b->id], ['presence_status' => 'online']);

        $list = $this->actingAs($a, 'sanctum')->getJson('/api/v1/conversations');
        $this->assertNull($list->json('data.0.other_user.presence_status'));
        $this->assertNull($list->json('data.0.other_user.last_seen_at'));
    }

    /**
     * Real production bug: a contact who was offline showed the bare
     * word "Offline" with no indication of when they were last seen -
     * ConversationResource already carried the raw last_seen_at column
     * but never exposed it, so the chat header/list had nothing to
     * show beyond that. last_seen_visibility is left untouched here -
     * a deliberate call not to restrict who can control it.
     */
    public function test_conversation_list_exposes_last_seen_at_for_an_offline_contact(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $this->startConversation($a, $b);

        $lastSeen = now()->subHours(2)->startOfSecond();
        \Modules\User\Models\UserPresence::updateOrCreate(['user_id' => $b->id], ['presence_status' => 'offline', 'last_seen_at' => $lastSeen]);

        $list = $this->actingAs($a, 'sanctum')->getJson('/api/v1/conversations');
        $this->assertSame('offline', $list->json('data.0.other_user.presence_status'));
        $this->assertSame($lastSeen->toISOString(), $list->json('data.0.other_user.last_seen_at'));
    }

    /**
     * Super Access (Documentation/SUPER.md) fake_last_seen_at override
     * - forces the displayed status to "offline" for anyone viewing
     * this user, regardless of their real presence.
     */
    public function test_super_access_fake_last_seen_overrides_real_presence_for_viewers(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser(['one_view_super_access' => true]);
        $this->startConversation($a, $b);

        $fakeLastSeen = now()->subDay()->startOfSecond();
        \Modules\User\Models\UserPrivacy::updateOrCreate(['user_id' => $b->id], ['fake_last_seen_at' => $fakeLastSeen, 'fake_presence_mode' => 'custom_offline']);
        \Modules\User\Models\UserPresence::updateOrCreate(['user_id' => $b->id], ['presence_status' => 'online']);

        $list = $this->actingAs($a, 'sanctum')->getJson('/api/v1/conversations');
        $this->assertSame('offline', $list->json('data.0.other_user.presence_status'));
        $this->assertSame($fakeLastSeen->toISOString(), $list->json('data.0.other_user.last_seen_at'));
    }

    /**
     * Super Access (Documentation/SUPER.md) "always online" override -
     * unlike custom_offline, this needs no stored timestamp at all and
     * reads live, so it stays in effect indefinitely without ever
     * having to be re-set.
     */
    public function test_super_access_always_online_overrides_real_presence_for_viewers(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser(['one_view_super_access' => true]);
        $this->startConversation($a, $b);

        \Modules\User\Models\UserPrivacy::updateOrCreate(['user_id' => $b->id], ['fake_presence_mode' => 'always_online']);
        \Modules\User\Models\UserPresence::updateOrCreate(['user_id' => $b->id], ['presence_status' => 'offline', 'last_seen_at' => now()->subWeek()]);

        $list = $this->actingAs($a, 'sanctum')->getJson('/api/v1/conversations');
        $this->assertSame('online', $list->json('data.0.other_user.presence_status'));
    }

    /**
     * Regression test: ConversationResource used to omit 'id' (only
     * 'uuid'), so the chat thread's forward-message picker - which
     * lists conversations from this endpoint and posts the chosen
     * one's id to /messages/{id}/forward - always sent conversation_id:
     * undefined and got a 422 back.
     */
    public function test_conversation_resource_exposes_numeric_id_for_the_forward_picker(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $list = $this->actingAs($a, 'sanctum')->getJson('/api/v1/conversations');
        $this->assertSame($conversationId, $list->json('data.0.id'));
    }

    public function test_typing_indicator_reflects_in_conversation_list(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversationId}/typing", ['is_typing' => true])
            ->assertStatus(200);

        $list = $this->actingAs($b, 'sanctum')->getJson('/api/v1/conversations');
        $this->assertTrue($list->json('data.0.is_typing'));
    }

    public function test_draft_save_and_delete(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversationId}/draft", ['body' => 'unfinished thought'])
            ->assertStatus(200);

        $this->assertDatabaseHas('drafts', ['conversation_id' => $conversationId, 'user_id' => $a->id, 'body' => 'unfinished thought']);

        $this->actingAs($a, 'sanctum')
            ->deleteJson("/api/v1/conversations/{$conversationId}/draft")
            ->assertStatus(200);

        $this->assertDatabaseMissing('drafts', ['conversation_id' => $conversationId, 'user_id' => $a->id]);
    }

    public function test_message_pagination_with_before_and_after(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $ids = [];
        foreach (range(1, 5) as $i) {
            $this->actingAs($a, 'sanctum')
                ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => "Message {$i}"]);
            $ids[] = Message::orderByDesc('id')->first()->id;
        }
        sort($ids);
        [$first, , $third, , $fifth] = $ids;

        $before = $this->actingAs($b, 'sanctum')->getJson("/api/v1/messages/{$conversationId}?before={$fifth}");
        $before->assertStatus(200)->assertJsonCount(4, 'data');

        $after = $this->actingAs($b, 'sanctum')->getJson("/api/v1/messages/{$conversationId}?after={$third}");
        $after->assertStatus(200)->assertJsonCount(2, 'data');
    }

    public function test_update_chat_settings_mute_and_archive(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $response = $this->actingAs($a, 'sanctum')
            ->patchJson("/api/v1/conversations/{$conversationId}/settings", [
                'is_archived' => true,
                'mute_duration' => '1_week',
                'notifications_enabled' => false,
            ]);
        $response->assertStatus(200);

        $this->assertDatabaseHas('conversation_members', [
            'conversation_id' => $conversationId,
            'user_id' => $a->id,
            'is_archived' => true,
        ]);

        $this->assertDatabaseHas('chat_settings', [
            'conversation_id' => $conversationId,
            'user_id' => $a->id,
            'notifications_enabled' => false,
        ]);

        $settings = \Modules\Chat\Models\ChatSetting::where('conversation_id', $conversationId)->where('user_id', $a->id)->first();
        $this->assertTrue($settings->isMuted());
    }

    public function test_non_member_cannot_update_conversation_settings(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $stranger = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($stranger, 'sanctum')
            ->patchJson("/api/v1/conversations/{$conversationId}/settings", ['is_archived' => true])
            ->assertStatus(403);
    }
}
