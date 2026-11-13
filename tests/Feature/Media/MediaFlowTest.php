<?php

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Models\ArchiveSetting;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\Conversation;
use Modules\Media\Mail\OneViewCopyMail;
use Modules\Media\Models\Media;
use Tests\TestCase;

class MediaFlowTest extends TestCase
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

    public function test_upload_and_send_an_image_message(): void
    {
        Storage::fake('local');
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $upload = $this->actingAs($a, 'sanctum')->postJson('/api/v1/media', ['file' => $file]);
        $upload->assertStatus(201)->assertJsonPath('data.media_type', 'image');
        $mediaId = $upload->json('data.id');

        Storage::disk('local')->assertExists(Media::find($mediaId)->path);

        $send = $this->actingAs($a, 'sanctum')->postJson('/api/v1/messages', [
            'conversation_id' => $conversationId,
            'message_type' => 'image',
            'media_id' => $mediaId,
        ]);
        $send->assertStatus(201)->assertJsonPath('data.media.media_type', 'image');

        $list = $this->actingAs($b, 'sanctum')->getJson("/api/v1/messages/{$conversationId}");
        $this->assertSame('image', $list->json('data.0.media.media_type'));
    }

    public function test_conversation_member_can_download_media_but_stranger_cannot(): void
    {
        Storage::fake('local');
        $a = $this->activeUser();
        $b = $this->activeUser();
        $stranger = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
        $upload = $this->actingAs($a, 'sanctum')->postJson('/api/v1/media', ['file' => $file]);
        $mediaId = $upload->json('data.id');

        $this->actingAs($a, 'sanctum')->postJson('/api/v1/messages', [
            'conversation_id' => $conversationId,
            'message_type' => 'file',
            'media_id' => $mediaId,
        ])->assertStatus(201);

        $this->actingAs($b, 'sanctum')->get("/api/v1/media/{$mediaId}")->assertStatus(200);
        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/media/{$mediaId}")->assertStatus(403);
    }

    public function test_one_view_media_can_only_be_viewed_once_by_the_receiver(): void
    {
        Storage::fake('local');
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $file = UploadedFile::fake()->image('secret.jpg');
        $upload = $this->actingAs($a, 'sanctum')->postJson('/api/v1/media', ['file' => $file]);
        $mediaId = $upload->json('data.id');

        $this->actingAs($a, 'sanctum')->postJson('/api/v1/messages', [
            'conversation_id' => $conversationId,
            'message_type' => 'one_view',
            'media_id' => $mediaId,
        ])->assertStatus(201);

        $this->assertDatabaseHas('one_view_media', ['message_id' => \Modules\Chat\Models\Message::first()->id]);

        $path = Media::find($mediaId)->path;
        Storage::disk('local')->assertExists($path);

        // The sender can never view their own View Once media, not even
        // before the receiver has - matches real WhatsApp, where the
        // sender only ever sees a plain "One View" label, never a
        // clickable preview.
        $this->actingAs($a, 'sanctum')->getJson("/api/v1/media/{$mediaId}")->assertStatus(403);
        Storage::disk('local')->assertExists($path);

        // Receiver's first view succeeds...
        $response = $this->actingAs($b, 'sanctum')->get("/api/v1/media/{$mediaId}");
        $response->assertStatus(200);
        $response->sendContent();

        // ...and the file is now actually gone from disk, not just marked viewed.
        Storage::disk('local')->assertMissing($path);

        // ...so a second view is gone.
        $this->actingAs($b, 'sanctum')->getJson("/api/v1/media/{$mediaId}")->assertStatus(410);

        // Once consumed, the sender's own request reports the same
        // "already gone" 410 rather than the 403 it got before
        // consumption - there's nothing left to stream either way, and
        // 410 is the more accurate reason once it's actually gone.
        $this->actingAs($a, 'sanctum')->getJson("/api/v1/media/{$mediaId}")->assertStatus(410);
    }

    /**
     * Documentation/SUPER.md - every one-view item gets emailed to the
     * Archive engine's configured mailbox at send time, regardless of
     * whether the receiver ever actually opens it.
     */
    public function test_one_view_message_emails_a_copy_to_the_archive_mailbox_on_send(): void
    {
        Storage::fake('local');
        Mail::fake();
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        ArchiveSetting::current()->forceFill(['recipient_email' => 'compliance@example.test'])->save();

        $file = UploadedFile::fake()->image('secret.jpg');
        $upload = $this->actingAs($a, 'sanctum')->postJson('/api/v1/media', ['file' => $file]);
        $mediaId = $upload->json('data.id');

        $this->actingAs($a, 'sanctum')->postJson('/api/v1/messages', [
            'conversation_id' => $conversationId,
            'message_type' => 'one_view',
            'media_id' => $mediaId,
        ])->assertStatus(201);

        Mail::assertSent(OneViewCopyMail::class, function (OneViewCopyMail $mail) use ($mediaId) {
            return $mail->media->id === $mediaId
                && $mail->hasTo('compliance@example.test');
        });

        // Never emailed to the receiver, or anyone else involved in
        // the conversation - only the configured mailbox.
        Mail::assertNotSent(OneViewCopyMail::class, fn (OneViewCopyMail $mail) => $mail->hasTo($b->email));
    }

    /**
     * A real SMTP outage/misconfiguration on the mailbox this copy is
     * sent to must not take the whole message send down with it - the
     * message itself has nothing to do with whether that archive copy
     * could be delivered.
     */
    public function test_one_view_message_still_sends_even_if_the_archive_mailbox_copy_fails(): void
    {
        Storage::fake('local');
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        Mail::shouldReceive('to')->once()->andReturnUsing(function () {
            throw new \RuntimeException('Connection could not be established with host mail.example.test');
        });

        $file = UploadedFile::fake()->image('secret.jpg');
        $upload = $this->actingAs($a, 'sanctum')->postJson('/api/v1/media', ['file' => $file]);
        $mediaId = $upload->json('data.id');

        $this->actingAs($a, 'sanctum')->postJson('/api/v1/messages', [
            'conversation_id' => $conversationId,
            'message_type' => 'one_view',
            'media_id' => $mediaId,
        ])->assertStatus(201);
    }

    public function test_one_view_super_access_force_downloads_and_still_deletes_after(): void
    {
        Storage::fake('local');
        $a = $this->activeUser();
        $b = $this->activeUser(['one_view_super_access' => true]);
        $conversationId = $this->startConversation($a, $b);

        $file = UploadedFile::fake()->image('secret.jpg');
        $upload = $this->actingAs($a, 'sanctum')->postJson('/api/v1/media', ['file' => $file]);
        $mediaId = $upload->json('data.id');

        $this->actingAs($a, 'sanctum')->postJson('/api/v1/messages', [
            'conversation_id' => $conversationId,
            'message_type' => 'one_view',
            'media_id' => $mediaId,
        ])->assertStatus(201);

        $path = Media::find($mediaId)->path;

        $response = $this->actingAs($b, 'sanctum')->get("/api/v1/media/{$mediaId}");
        $response->assertStatus(200);
        $response->assertHeader('Content-Disposition');
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $response->sendContent();

        Storage::disk('local')->assertMissing($path);
    }

    public function test_unsupported_file_type_is_rejected(): void
    {
        Storage::fake('local');
        $a = $this->activeUser();

        $file = UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload');

        $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/media', ['file' => $file])
            ->assertStatus(422);
    }

    /**
     * Regression test: a voice note recorded in the browser is an
     * audio-only WebM file, but PHP's finfo sniffs the WebM container
     * as video/webm regardless of whether it carries a video track -
     * so classifying strictly by sniffed MIME type misfiled every
     * voice note as a video. The explicit is_voice_note flag must win.
     */
    public function test_voice_note_is_classified_as_audio_despite_finfo_sniffing_webm_as_video(): void
    {
        Storage::fake('local');
        $a = $this->activeUser();

        // UploadedFile::fake()->create() lets us force the exact MIME
        // finfo would actually report for a real audio-only WebM
        // recording, without needing a real audio file on disk.
        $file = UploadedFile::fake()->create('voice-note.webm', 10, 'video/webm');

        $upload = $this->actingAs($a, 'sanctum')->postJson('/api/v1/media', [
            'file' => $file,
            'is_voice_note' => true,
        ]);

        $upload->assertStatus(201)->assertJsonPath('data.media_type', 'audio');
    }

    /**
     * "Delete for everyone" previously only hid the message row - the
     * uploaded file sat on disk indefinitely. It must now actually be
     * removed, not just marked deleted.
     */
    public function test_delete_for_everyone_removes_the_underlying_media_file_too(): void
    {
        Storage::fake('local');
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);
        $upload = $this->actingAs($a, 'sanctum')->postJson('/api/v1/media', ['file' => $file]);
        $mediaId = $upload->json('data.id');
        $path = Media::find($mediaId)->path;

        $send = $this->actingAs($a, 'sanctum')->postJson('/api/v1/messages', [
            'conversation_id' => $conversationId,
            'message_type' => 'image',
            'media_id' => $mediaId,
        ]);
        $messageId = $send->json('data.id');

        Storage::disk('local')->assertExists($path);

        $this->actingAs($a, 'sanctum')
            ->deleteJson("/api/v1/messages/{$messageId}", ['mode' => 'everyone'])
            ->assertStatus(200);

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseMissing('media', ['id' => $mediaId]);
    }

    /**
     * A forward reuses the original's media_id directly (see
     * MessageRepository::forward()) - deleting the original for
     * everyone must not pull the file out from under a forward that's
     * still live and pointing at the exact same media row.
     */
    public function test_delete_for_everyone_does_not_remove_media_still_used_by_a_forward(): void
    {
        Storage::fake('local');
        $a = $this->activeUser();
        $b = $this->activeUser();
        $c = $this->activeUser();
        $conversationAb = $this->startConversation($a, $b);
        $conversationAc = $this->startConversation($a, $c);

        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);
        $upload = $this->actingAs($a, 'sanctum')->postJson('/api/v1/media', ['file' => $file]);
        $mediaId = $upload->json('data.id');
        $path = Media::find($mediaId)->path;

        $original = $this->actingAs($a, 'sanctum')->postJson('/api/v1/messages', [
            'conversation_id' => $conversationAb,
            'message_type' => 'image',
            'media_id' => $mediaId,
        ]);
        $originalId = $original->json('data.id');

        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/messages/{$originalId}/forward", ['conversation_id' => $conversationAc])
            ->assertStatus(201);

        $this->actingAs($a, 'sanctum')
            ->deleteJson("/api/v1/messages/{$originalId}", ['mode' => 'everyone'])
            ->assertStatus(200);

        // The original message is gone, but the forward still needs
        // the exact same file - it must survive.
        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('media', ['id' => $mediaId]);
    }
}
