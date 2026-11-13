<?php

namespace Tests\Feature\Chat;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\Conversation;
use Modules\Media\Models\Media;
use Tests\TestCase;

/** Security (own test 2026-11-06): a message may only carry a file its sender uploaded, else anybody's file could be opened. */
class MediaOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(): User
    {
        return User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    }

    public function test_a_message_cannot_carry_somebody_elses_file(): void
    {
        Storage::fake('local');
        [$victim, $thief, $friend] = [$this->activeUser(), $this->activeUser(), $this->activeUser()];
        Storage::disk('local')->put('media/private.txt', 'secret bytes');
        $media = Media::create(['uploader_id' => $victim->id, 'disk' => 'local', 'path' => 'media/private.txt', 'original_filename' => 'private.txt',
            'mime_type' => 'text/plain', 'size_bytes' => 12, 'media_type' => 'document']);

        $uuid = $this->actingAs($thief, 'sanctum')->postJson('/api/v1/conversations', ['participant_id' => $friend->id])->json('data.uuid');
        $conversationId = Conversation::where('uuid', $uuid)->value('id');

        $this->actingAs($thief, 'sanctum')->getJson('/api/v1/media/' . $media->id)->assertForbidden();
        $this->actingAs($thief, 'sanctum')->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'message_type' => 'file', 'media_id' => $media->id])
            ->assertStatus(422)->assertJsonValidationErrors('media_id');
        $first = $this->actingAs($thief, 'sanctum')->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'hi'])->json('data.id');
        $this->actingAs($thief, 'sanctum')->postJson('/api/v1/messages/' . $first . '/reply', ['message_type' => 'file', 'media_id' => $media->id])->assertStatus(422);
        $this->actingAs($thief, 'sanctum')->getJson('/api/v1/media/' . $media->id)->assertForbidden();

        // the owner can still send their own file
        Storage::disk('local')->put('media/mine.txt', 'mine');
        $mine = Media::create(['uploader_id' => $thief->id, 'disk' => 'local', 'path' => 'media/mine.txt', 'original_filename' => 'mine.txt',
            'mime_type' => 'text/plain', 'size_bytes' => 4, 'media_type' => 'document']);
        $this->actingAs($thief, 'sanctum')->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'message_type' => 'file', 'media_id' => $mine->id])->assertCreated();
        $this->actingAs($friend, 'sanctum')->get('/api/v1/media/' . $mine->id)->assertOk();
    }
}
