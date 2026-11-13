<?php

namespace Tests\Feature\Chat;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\ConversationMember;
use Modules\Chat\Models\Message;
use Tests\TestCase;

class EndToEndEncryptionTest extends TestCase
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

    public function test_encryption_status_auto_issues_a_key_the_first_time_its_checked(): void
    {
        $alice = $this->activeUser();
        $bob = $this->activeUser();
        $conversationId = $this->startConversation($alice, $bob);

        $response = $this->actingAs($alice, 'sanctum')->getJson("/api/v1/conversations/{$conversationId}/encryption")
            ->assertOk();

        $this->assertTrue($response->json('data.eligible'));
        $this->assertTrue($response->json('data.e2e_enabled'));
        $this->assertSame(1, $response->json('data.current_key_version'));
        $this->assertTrue(Conversation::find($conversationId)->e2e_enabled);
    }

    public function test_the_same_key_version_is_returned_on_a_second_status_check_no_needless_rotation(): void
    {
        $alice = $this->activeUser();
        $bob = $this->activeUser();
        $conversationId = $this->startConversation($alice, $bob);

        $first = $this->actingAs($alice, 'sanctum')->getJson("/api/v1/conversations/{$conversationId}/encryption")->json('data.current_key_version');
        $second = $this->actingAs($bob, 'sanctum')->getJson("/api/v1/conversations/{$conversationId}/encryption")->json('data.current_key_version');

        $this->assertSame(1, $first);
        $this->assertSame(1, $second);
        $this->assertSame(1, \Modules\Chat\Models\ConversationKey::where('conversation_id', $conversationId)->count());
    }

    public function test_any_current_member_can_fetch_the_raw_key_material_for_an_issued_epoch(): void
    {
        $alice = $this->activeUser();
        $bob = $this->activeUser();
        $conversationId = $this->startConversation($alice, $bob);

        $version = $this->actingAs($alice, 'sanctum')->getJson("/api/v1/conversations/{$conversationId}/encryption")->json('data.current_key_version');

        $aliceCopy = $this->actingAs($alice, 'sanctum')->getJson("/api/v1/conversations/{$conversationId}/encryption/keys/{$version}")
            ->assertOk()->json('data.key_material');
        $bobCopy = $this->actingAs($bob, 'sanctum')->getJson("/api/v1/conversations/{$conversationId}/encryption/keys/{$version}")
            ->assertOk()->json('data.key_material');

        $this->assertNotEmpty($aliceCopy);
        $this->assertSame($aliceCopy, $bobCopy, 'both members should get the same raw key material for the same epoch');
    }

    public function test_a_non_member_cannot_fetch_a_conversations_key_material(): void
    {
        $alice = $this->activeUser();
        $bob = $this->activeUser();
        $stranger = $this->activeUser();
        $conversationId = $this->startConversation($alice, $bob);

        $version = $this->actingAs($alice, 'sanctum')->getJson("/api/v1/conversations/{$conversationId}/encryption")->json('data.current_key_version');

        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/conversations/{$conversationId}/encryption/keys/{$version}")->assertStatus(403);
    }

    public function test_a_membership_change_rotates_to_a_new_key_epoch_automatically(): void
    {
        $alice = $this->activeUser();
        $bob = $this->activeUser();
        $conversationId = $this->startConversation($alice, $bob);

        $v1 = $this->actingAs($alice, 'sanctum')->getJson("/api/v1/conversations/{$conversationId}/encryption")->json('data.current_key_version');
        $this->assertSame(1, $v1);

        $carol = $this->activeUser();
        ConversationMember::create([
            'conversation_id' => $conversationId,
            'user_id' => $carol->id,
            'joined_at' => now(),
            'role' => 'member',
        ]);

        $v2 = $this->actingAs($alice, 'sanctum')->getJson("/api/v1/conversations/{$conversationId}/encryption")->json('data.current_key_version');
        $this->assertSame(2, $v2);

        // Carol can fetch the new epoch (she's a current member), but
        // never the one issued before she joined.
        $this->actingAs($carol, 'sanctum')->getJson("/api/v1/conversations/{$conversationId}/encryption/keys/2")
            ->assertOk()->assertJsonPath('data.key_material', fn ($v) => ! empty($v));

        // A repeat status check with no further membership change stays at v2.
        $stillV2 = $this->actingAs($bob, 'sanctum')->getJson("/api/v1/conversations/{$conversationId}/encryption")->json('data.current_key_version');
        $this->assertSame(2, $stillV2);
    }

    public function test_a_group_over_the_configured_cap_is_not_eligible_and_gets_no_key(): void
    {
        config(['chat.e2e_max_group_members' => 2]);

        $alice = $this->activeUser();
        $bob = $this->activeUser();
        $conversationId = $this->startConversation($alice, $bob);

        $carol = $this->activeUser();
        ConversationMember::create([
            'conversation_id' => $conversationId,
            'user_id' => $carol->id,
            'joined_at' => now(),
            'role' => 'member',
        ]);

        $status = $this->actingAs($alice, 'sanctum')->getJson("/api/v1/conversations/{$conversationId}/encryption")->assertOk();
        $this->assertFalse($status->json('data.eligible'));
        $this->assertFalse($status->json('data.e2e_enabled'));
        $this->assertNull($status->json('data.current_key_version'));
        $this->assertSame(0, \Modules\Chat\Models\ConversationKey::where('conversation_id', $conversationId)->count());
    }

    public function test_sending_an_e2e_message_stores_ciphertext_fields_and_the_resource_exposes_them(): void
    {
        $alice = $this->activeUser();
        $bob = $this->activeUser();
        $conversationId = $this->startConversation($alice, $bob);

        $response = $this->actingAs($alice, 'sanctum')->postJson('/api/v1/messages', [
            'conversation_id' => $conversationId,
            'body' => 'ciphertext-blob',
            'encryption_mode' => 'e2e',
            'iv' => 'base64-iv',
            'key_version' => 1,
        ])->assertStatus(201);

        $response->assertJsonPath('data.encryption_mode', 'e2e');
        $response->assertJsonPath('data.body', 'ciphertext-blob');
        $response->assertJsonPath('data.iv', 'base64-iv');
        $response->assertJsonPath('data.key_version', 1);

        $message = Message::latest('id')->first();
        $this->assertSame('e2e', $message->encryption_mode);
        $this->assertSame('ciphertext-blob', $message->body);
    }

    public function test_replying_to_an_e2e_message_stores_an_encrypted_preview_with_its_own_iv_not_a_plaintext_substring(): void
    {
        $alice = $this->activeUser();
        $bob = $this->activeUser();
        $conversationId = $this->startConversation($alice, $bob);

        $original = $this->actingAs($alice, 'sanctum')->postJson('/api/v1/messages', [
            'conversation_id' => $conversationId,
            'body' => 'original-ciphertext',
            'encryption_mode' => 'e2e',
            'iv' => 'original-iv',
            'key_version' => 1,
        ])->json('data');

        $reply = $this->actingAs($bob, 'sanctum')->postJson("/api/v1/messages/{$original['id']}/reply", [
            'body' => 'reply-ciphertext',
            'encryption_mode' => 'e2e',
            'iv' => 'reply-iv',
            'key_version' => 1,
            'reply_preview' => 'preview-ciphertext',
            'reply_preview_iv' => 'preview-iv',
        ])->assertStatus(201);

        $reply->assertJsonPath('data.reply_to.preview', 'preview-ciphertext');
        $reply->assertJsonPath('data.reply_to.preview_iv', 'preview-iv');
    }

    public function test_forwarding_an_e2e_message_requires_client_supplied_re_encrypted_content(): void
    {
        $alice = $this->activeUser();
        $bob = $this->activeUser();
        $carol = $this->activeUser();
        $conversationId = $this->startConversation($alice, $bob);
        $otherConversationId = $this->startConversation($alice, $carol);

        $original = $this->actingAs($alice, 'sanctum')->postJson('/api/v1/messages', [
            'conversation_id' => $conversationId,
            'body' => 'ciphertext',
            'encryption_mode' => 'e2e',
            'iv' => 'iv',
            'key_version' => 1,
        ])->json('data');

        // No re-encrypted content supplied - the server refuses to just
        // copy ciphertext into a conversation with a different key.
        $this->actingAs($alice, 'sanctum')->postJson("/api/v1/messages/{$original['id']}/forward", [
            'conversation_id' => $otherConversationId,
        ])->assertStatus(422);

        // Re-encrypted for the destination - succeeds.
        $forwarded = $this->actingAs($alice, 'sanctum')->postJson("/api/v1/messages/{$original['id']}/forward", [
            'conversation_id' => $otherConversationId,
            'body' => 're-encrypted-ciphertext',
            'encryption_mode' => 'e2e',
            'iv' => 'new-iv',
            'key_version' => 1,
        ])->assertStatus(201);

        $forwarded->assertJsonPath('data.body', 're-encrypted-ciphertext');

        // Forwarding a plain (never-encrypted) message still needs no
        // extra fields at all - completely unaffected.
        $plainOriginal = $this->actingAs($alice, 'sanctum')->postJson('/api/v1/messages', [
            'conversation_id' => $conversationId,
            'body' => 'plain text',
        ])->json('data');

        $this->actingAs($alice, 'sanctum')->postJson("/api/v1/messages/{$plainOriginal['id']}/forward", [
            'conversation_id' => $otherConversationId,
        ])->assertStatus(201)->assertJsonPath('data.body', 'plain text');
    }

    public function test_migrating_historical_messages_only_touches_rows_still_genuinely_plaintext(): void
    {
        $alice = $this->activeUser();
        $bob = $this->activeUser();
        $conversationId = $this->startConversation($alice, $bob);

        $this->actingAs($alice, 'sanctum')->getJson("/api/v1/conversations/{$conversationId}/encryption")->assertOk();

        $plainMessage = $this->actingAs($alice, 'sanctum')->postJson('/api/v1/messages', [
            'conversation_id' => $conversationId,
            'body' => 'old plaintext message',
        ])->json('data');

        $alreadyEncrypted = $this->actingAs($bob, 'sanctum')->postJson('/api/v1/messages', [
            'conversation_id' => $conversationId,
            'body' => 'already-ciphertext',
            'encryption_mode' => 'e2e',
            'iv' => 'iv',
            'key_version' => 1,
        ])->json('data');

        $response = $this->actingAs($alice, 'sanctum')->postJson("/api/v1/conversations/{$conversationId}/messages/migrate-to-encrypted", [
            'key_version' => 1,
            'messages' => [
                ['message_id' => $plainMessage['id'], 'body' => 'migrated-ciphertext', 'iv' => 'migrated-iv'],
                // Attempting to touch an already-encrypted message is a
                // no-op (encryption_mode isn't 'none'), not an overwrite.
                ['message_id' => $alreadyEncrypted['id'], 'body' => 'should-not-apply', 'iv' => 'should-not-apply'],
            ],
        ])->assertOk();

        $this->assertSame(1, $response->json('data.updated'));

        $migrated = Message::find($plainMessage['id']);
        $this->assertSame('e2e', $migrated->encryption_mode);
        $this->assertSame('migrated-ciphertext', $migrated->body);

        $untouched = Message::find($alreadyEncrypted['id']);
        $this->assertSame('already-ciphertext', $untouched->body);
    }
}
