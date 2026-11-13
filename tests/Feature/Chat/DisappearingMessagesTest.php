<?php

namespace Tests\Feature\Chat;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Chat\Jobs\CleanupExpiredDisappearingMessagesJob;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\Message;
use Tests\TestCase;

class DisappearingMessagesTest extends TestCase
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

    public function test_a_member_can_turn_on_disappearing_messages_and_new_sends_get_an_expiry(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($a, 'sanctum')
            ->patchJson("/api/v1/conversations/{$conversationId}/disappearing-messages", ['duration' => '24_hours'])
            ->assertStatus(200)
            ->assertJsonPath('data.disappearing_messages_seconds', 86400);

        $this->assertDatabaseHas('conversations', ['id' => $conversationId, 'disappearing_messages_seconds' => 86400]);

        $send = $this->actingAs($b, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'this will vanish']);

        $send->assertStatus(201);
        $message = Message::find($send->json('data.id'));
        $this->assertNotNull($message->expires_at);
        $this->assertTrue($message->expires_at->between(now()->addHours(23), now()->addHours(25)));
        $this->assertNotNull($send->json('data.expires_at'));
    }

    public function test_turning_it_off_stops_new_messages_from_getting_an_expiry(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($a, 'sanctum')->patchJson("/api/v1/conversations/{$conversationId}/disappearing-messages", ['duration' => '7_days']);
        $this->actingAs($a, 'sanctum')
            ->patchJson("/api/v1/conversations/{$conversationId}/disappearing-messages", ['duration' => 'off'])
            ->assertJsonPath('data.disappearing_messages_seconds', null);

        $send = $this->actingAs($b, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'stays forever']);

        $this->assertNull(Message::find($send->json('data.id'))->expires_at);
    }

    public function test_a_non_member_cannot_toggle_disappearing_messages(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $stranger = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($stranger, 'sanctum')
            ->patchJson("/api/v1/conversations/{$conversationId}/disappearing-messages", ['duration' => '24_hours'])
            ->assertStatus(403);
    }

    public function test_cleanup_job_genuinely_deletes_expired_messages_but_leaves_others_alone(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $expiredId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'old news'])
            ->json('data.id');
        $futureId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'still relevant'])
            ->json('data.id');
        $neverId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'permanent'])
            ->json('data.id');

        Message::whereKey($expiredId)->update(['expires_at' => now()->subMinute()]);
        Message::whereKey($futureId)->update(['expires_at' => now()->addDay()]);

        app(CleanupExpiredDisappearingMessagesJob::class)->handle(app(\Modules\Chat\Repositories\MessageRepository::class));

        // A real hard delete now - not a soft-deleted tombstone still
        // sitting in the table (readable via withTrashed(), as it used
        // to be before this fix).
        $this->assertNull(Message::withTrashed()->find($expiredId));
        $this->assertDatabaseMissing('messages', ['id' => $expiredId]);

        $this->assertNotNull(Message::find($futureId));
        $this->assertNotNull(Message::find($neverId));
    }

    public function test_cleanup_job_deletes_the_attached_media_file_for_an_expired_message(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $media = \Modules\Media\Models\Media::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'uploader_id' => $a->id,
            'disk' => 'public',
            'path' => 'chat-media/expiring-photo.jpg',
            'original_filename' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'image',
            'size_bytes' => 1024,
        ]);
        \Illuminate\Support\Facades\Storage::disk('public')->put($media->path, 'fake-bytes');

        $messageId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', [
                'conversation_id' => $conversationId,
                'message_type' => 'image',
                'media_id' => $media->id,
            ])
            ->json('data.id');

        Message::whereKey($messageId)->update(['expires_at' => now()->subMinute()]);

        app(CleanupExpiredDisappearingMessagesJob::class)->handle(app(\Modules\Chat\Repositories\MessageRepository::class));

        $this->assertDatabaseMissing('messages', ['id' => $messageId]);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($media->path);
    }

    public function test_turning_on_after_seen_does_not_set_a_fixed_expiry_but_flags_the_message(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($a, 'sanctum')
            ->patchJson("/api/v1/conversations/{$conversationId}/disappearing-messages", ['duration' => 'after_seen'])
            ->assertStatus(200)
            ->assertJsonPath('data.disappearing_messages_seconds', 0);

        $this->assertDatabaseHas('conversations', ['id' => $conversationId, 'disappearing_messages_seconds' => 0]);

        $send = $this->actingAs($b, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'gone once you see it']);

        $send->assertStatus(201);
        $message = Message::find($send->json('data.id'));
        $this->assertNull($message->expires_at);
        $this->assertTrue($message->expire_on_read);
    }

    public function test_an_after_seen_message_is_purged_the_moment_the_recipient_marks_it_read(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($a, 'sanctum')->patchJson("/api/v1/conversations/{$conversationId}/disappearing-messages", ['duration' => 'after_seen']);

        $messageId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'blink and it is gone'])
            ->json('data.id');

        // Not deleted just for existing - only once actually read.
        $this->assertNotNull(Message::find($messageId));

        $this->actingAs($b, 'sanctum')->postJson("/api/v1/conversations/{$conversationId}/read")->assertStatus(200);

        $this->assertNull(Message::withTrashed()->find($messageId));
        $this->assertDatabaseMissing('messages', ['id' => $messageId]);
    }

    public function test_an_after_seen_message_is_left_alone_until_it_is_actually_read(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($a, 'sanctum')->patchJson("/api/v1/conversations/{$conversationId}/disappearing-messages", ['duration' => 'after_seen']);

        $messageId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'not yet seen'])
            ->json('data.id');

        // The sender polling their own conversation must not trigger
        // "read" (and therefore deletion) of their own just-sent message.
        $this->actingAs($a, 'sanctum')->postJson("/api/v1/conversations/{$conversationId}/read")->assertStatus(200);

        $this->assertNotNull(Message::find($messageId));

        app(CleanupExpiredDisappearingMessagesJob::class)->handle(app(\Modules\Chat\Repositories\MessageRepository::class));
        $this->assertNotNull(Message::find($messageId), 'an after-seen message must never be swept up by the fixed-timer cleanup job since it has no expires_at');
    }
}
