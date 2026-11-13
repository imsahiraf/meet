<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // 'none' - plaintext (large groups over the E2E cap, or not
            // yet migrated). 'e2e' - body is AES-GCM ciphertext under
            // the conversation's key_version, unreadable server-side.
            // 'server' - encrypted at rest with the app key (View Once
            // only, so the existing admin BCC copy can still decrypt it
            // server-side - a deliberate, disclosed exception, not real
            // E2E).
            $table->enum('encryption_mode', ['none', 'e2e', 'server'])->default('none')->after('body');
            $table->unsignedInteger('key_version')->nullable()->after('encryption_mode');
            $table->string('iv')->nullable()->after('key_version');
        });

        Schema::table('conversations', function (Blueprint $table) {
            // Cached rather than recomputed from member count on every
            // request - flipped whenever a conversation_keys row is
            // issued/rotated (ConversationEncryptionService), and back
            // off the moment a group crosses e2e_max_group_members.
            $table->boolean('e2e_enabled')->default(false)->after('disappearing_messages_seconds');
        });

        Schema::table('message_replies', function (Blueprint $table) {
            // When the quoted message is E2E, replied_to_preview holds
            // the full ciphertext of a client-truncated preview
            // (re-encrypted under the reply's own conversation key, with
            // its own IV - never reuse the original message's IV) instead
            // of a plaintext substring, so the server never computes or
            // stores a readable preview of encrypted content.
            $table->string('replied_to_preview_iv')->nullable()->after('replied_to_preview');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['encryption_mode', 'key_version', 'iv']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('e2e_enabled');
        });

        Schema::table('message_replies', function (Blueprint $table) {
            $table->dropColumn('replied_to_preview_iv');
        });
    }
};
