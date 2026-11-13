<?php

namespace Tests\Feature\Chat;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\Conversation;
use Modules\Media\Models\Media;
use Tests\TestCase;
use ZipArchive;

class ChatExportTest extends TestCase
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

    public function test_export_produces_a_zip_with_a_transcript_csv_and_the_conversations_media(): void
    {
        Storage::fake('local');

        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'Hello there']);

        Storage::disk('local')->put('media/2026/07/test-export.txt', 'fake file bytes');
        $media = Media::create([
            'uploader_id' => $b->id,
            'disk' => 'local',
            'path' => 'media/2026/07/test-export.txt',
            'original_filename' => 'notes.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 16,
            'media_type' => 'document',
        ]);

        $mediaMessageId = $this->actingAs($b, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'message_type' => 'file', 'media_id' => $media->id])
            ->json('data.id');

        $response = $this->actingAs($a, 'sanctum')->get("/api/v1/conversations/{$conversationId}/export");
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/zip');

        $tmpZipPath = sys_get_temp_dir().'/chat-export-test-'.uniqid().'.zip';
        file_put_contents($tmpZipPath, $response->streamedContent());

        $zip = new ZipArchive();
        $zip->open($tmpZipPath);

        $csv = $zip->getFromName('transcript.csv');
        $this->assertNotFalse($csv, 'expected transcript.csv inside the exported zip');
        $this->assertStringContainsString('Hello there', $csv);
        $this->assertStringContainsString('[Attachment: notes.txt]', $csv);

        $mediaEntry = "media/{$mediaMessageId}-notes.txt";
        $this->assertNotFalse($zip->locateName($mediaEntry), "expected {$mediaEntry} inside the exported zip");
        $this->assertSame('fake file bytes', $zip->getFromName($mediaEntry));

        $zip->close();
        unlink($tmpZipPath);
    }

    public function test_an_e2e_message_exports_as_a_placeholder_not_raw_ciphertext(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($a, 'sanctum')->postJson('/api/v1/messages', [
            'conversation_id' => $conversationId,
            'body' => 'unreadable-ciphertext-blob',
            'encryption_mode' => 'e2e',
            'iv' => 'iv',
            'key_version' => 1,
        ]);

        $response = $this->actingAs($a, 'sanctum')->get("/api/v1/conversations/{$conversationId}/export");
        $tmpZipPath = sys_get_temp_dir().'/chat-export-test-'.uniqid().'.zip';
        file_put_contents($tmpZipPath, $response->streamedContent());

        $zip = new ZipArchive();
        $zip->open($tmpZipPath);
        $csv = $zip->getFromName('transcript.csv');
        $zip->close();
        unlink($tmpZipPath);

        $this->assertStringNotContainsString('unreadable-ciphertext-blob', $csv);
        $this->assertStringContainsString('[End-to-end encrypted message - not readable in this export]', $csv);
    }

    public function test_a_non_member_cannot_export_a_conversation(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $stranger = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $this->actingAs($stranger, 'sanctum')->get("/api/v1/conversations/{$conversationId}/export")->assertStatus(403);
    }

    public function test_a_message_the_user_deleted_for_themselves_is_excluded_from_their_own_export(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversationId = $this->startConversation($a, $b);

        $send = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'oops wrong chat']);
        $messageId = $send->json('data.id');

        $this->actingAs($a, 'sanctum')->deleteJson("/api/v1/messages/{$messageId}", ['mode' => 'me']);

        $response = $this->actingAs($a, 'sanctum')->get("/api/v1/conversations/{$conversationId}/export");
        $tmpZipPath = sys_get_temp_dir().'/chat-export-test-'.uniqid().'.zip';
        file_put_contents($tmpZipPath, $response->streamedContent());

        $zip = new ZipArchive();
        $zip->open($tmpZipPath);
        $csv = $zip->getFromName('transcript.csv');
        $zip->close();
        unlink($tmpZipPath);

        $this->assertStringNotContainsString('oops wrong chat', $csv);

        // But Bob (who didn't delete it for himself) still sees it in his own export.
        $responseB = $this->actingAs($b, 'sanctum')->get("/api/v1/conversations/{$conversationId}/export");
        $tmpZipPathB = sys_get_temp_dir().'/chat-export-test-'.uniqid().'.zip';
        file_put_contents($tmpZipPathB, $responseB->streamedContent());
        $zipB = new ZipArchive();
        $zipB->open($tmpZipPathB);
        $csvB = $zipB->getFromName('transcript.csv');
        $zipB->close();
        unlink($tmpZipPathB);

        $this->assertStringContainsString('oops wrong chat', $csvB);
    }
}
