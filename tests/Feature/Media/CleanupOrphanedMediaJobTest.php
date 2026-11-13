<?php

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\Message;
use Modules\Groups\Models\Group;
use Modules\Groups\Models\GroupMedia;
use Modules\Media\Jobs\CleanupOrphanedMediaJob;
use Modules\Media\Models\Media;
use Modules\Media\Models\OneViewMedia;
use Tests\TestCase;

class CleanupOrphanedMediaJobTest extends TestCase
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

    private function uploadMedia(User $uploader, string $name = 'photo.jpg'): int
    {
        Mail::fake();

        return $this->actingAs($uploader, 'sanctum')
            ->postJson('/api/v1/media', ['file' => UploadedFile::fake()->image($name)])
            ->json('data.id');
    }

    /**
     * The synchronous stream-time delete (MediaController::show()) is
     * what normally cleans this up - this job only needs to catch the
     * one where that never finished (viewed_at got set, but the file
     * is still sitting there past a short grace window).
     */
    public function test_a_one_view_media_stuck_viewed_but_never_actually_deleted_is_cleaned_up_after_the_grace_window(): void
    {
        Storage::fake('local');
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);
        $mediaId = $this->uploadMedia($a, 'secret.jpg');
        $path = Media::find($mediaId)->path;

        $this->actingAs($a, 'sanctum')->postJson('/api/v1/messages', [
            'conversation_id' => $conversationId,
            'message_type' => 'one_view',
            'media_id' => $mediaId,
        ])->assertStatus(201);

        // Simulates the stream getting interrupted right after
        // viewed_at was recorded but before the delete ran.
        $message = Message::where('media_id', $mediaId)->first();
        OneViewMedia::where('message_id', $message->id)
            ->update(['viewed_by' => $b->id, 'viewed_at' => now()->subMinutes(20)]);

        Storage::disk('local')->assertExists($path);

        (new CleanupOrphanedMediaJob())->handle();

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseMissing('media', ['id' => $mediaId]);
        // The message and its OneViewMedia row (the "Opened" record)
        // must survive - only the file+Media row are gone.
        $this->assertDatabaseHas('messages', ['id' => $message->id]);
        $this->assertDatabaseHas('one_view_media', ['message_id' => $message->id]);
    }

    public function test_a_one_view_media_viewed_within_the_grace_window_is_left_alone(): void
    {
        Storage::fake('local');
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);
        $mediaId = $this->uploadMedia($a, 'secret.jpg');
        $path = Media::find($mediaId)->path;

        $this->actingAs($a, 'sanctum')->postJson('/api/v1/messages', [
            'conversation_id' => $conversationId,
            'message_type' => 'one_view',
            'media_id' => $mediaId,
        ]);

        $message = Message::where('media_id', $mediaId)->first();
        OneViewMedia::where('message_id', $message->id)
            ->update(['viewed_by' => $b->id, 'viewed_at' => now()->subMinutes(2)]);

        (new CleanupOrphanedMediaJob())->handle();

        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('media', ['id' => $mediaId]);
    }

    public function test_media_still_attached_to_a_message_is_never_touched(): void
    {
        Storage::fake('local');
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);
        $mediaId = $this->uploadMedia($a);
        $path = Media::find($mediaId)->path;

        $this->actingAs($a, 'sanctum')->postJson('/api/v1/messages', [
            'conversation_id' => $conversationId,
            'message_type' => 'image',
            'media_id' => $mediaId,
        ]);

        Media::where('id', $mediaId)->update(['created_at' => now()->subDays(5)]);

        (new CleanupOrphanedMediaJob())->handle();

        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('media', ['id' => $mediaId]);
    }

    public function test_a_recently_uploaded_attachment_never_sent_anywhere_is_kept_within_its_grace_day(): void
    {
        Storage::fake('local');
        $a = $this->activeUser();
        $mediaId = $this->uploadMedia($a);
        $path = Media::find($mediaId)->path;

        (new CleanupOrphanedMediaJob())->handle();

        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('media', ['id' => $mediaId]);
    }

    /**
     * An abandoned upload (the picker was used, but the message was
     * never actually sent) - the media/message endpoints are two
     * separate steps, so this is a real, reachable orphan.
     */
    public function test_an_abandoned_upload_older_than_a_day_with_no_message_ever_sent_is_deleted(): void
    {
        Storage::fake('local');
        $a = $this->activeUser();
        $mediaId = $this->uploadMedia($a);
        $path = Media::find($mediaId)->path;

        Media::where('id', $mediaId)->update(['created_at' => now()->subDays(2)]);

        (new CleanupOrphanedMediaJob())->handle();

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseMissing('media', ['id' => $mediaId]);
    }

    public function test_media_still_used_by_a_groups_media_library_is_never_touched(): void
    {
        Storage::fake('local');
        $a = $this->activeUser();
        $member = $this->activeUser();
        $mediaId = $this->uploadMedia($a);
        $path = Media::find($mediaId)->path;
        Media::where('id', $mediaId)->update(['created_at' => now()->subDays(5)]);

        $groupResponse = $this->actingAs($a, 'sanctum')->postJson('/api/v1/groups', [
            'group_name' => 'Test group',
            'description' => 'For orphan-media cleanup test',
            'members' => [$member->id],
        ]);
        $groupResponse->assertStatus(201);
        $groupId = Group::where('uuid', $groupResponse->json('data.uuid'))->firstOrFail()->id;

        GroupMedia::create(['group_id' => $groupId, 'media_id' => $mediaId, 'uploaded_by' => $a->id]);

        (new CleanupOrphanedMediaJob())->handle();

        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('media', ['id' => $mediaId]);
    }

    /**
     * A forward reuses the original's media_id directly - deleting an
     * old, otherwise-unreferenced media row must not pull the file out
     * from under whichever OTHER message still points at the exact
     * same row (mirrors MessageRepository::deleteMediaIfOrphaned()'s
     * own rule, applied here in bulk).
     */
    public function test_media_still_referenced_by_a_second_message_via_forward_is_kept(): void
    {
        Storage::fake('local');
        $a = $this->activeUser();
        $b = $this->activeUser();
        $c = $this->activeUser();
        $conversationAb = $this->startConversation($a, $b);
        $conversationAc = $this->startConversation($a, $c);
        $mediaId = $this->uploadMedia($a);
        $path = Media::find($mediaId)->path;

        $original = $this->actingAs($a, 'sanctum')->postJson('/api/v1/messages', [
            'conversation_id' => $conversationAb,
            'message_type' => 'image',
            'media_id' => $mediaId,
        ])->json('data.id');

        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/messages/{$original}/forward", ['conversation_id' => $conversationAc])
            ->assertStatus(201);

        Media::where('id', $mediaId)->update(['created_at' => now()->subDays(5)]);

        // The original is deleted for everyone - only the forward is left standing.
        $this->actingAs($a, 'sanctum')->deleteJson("/api/v1/messages/{$original}", ['mode' => 'everyone']);

        (new CleanupOrphanedMediaJob())->handle();

        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('media', ['id' => $mediaId]);
    }
}
