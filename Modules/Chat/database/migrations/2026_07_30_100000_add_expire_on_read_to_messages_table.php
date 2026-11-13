<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the "immediately after being seen" disappearing-messages
 * duration - a message that should vanish the instant the recipient's
 * read-receipt is recorded rather than after a fixed timer, so it has
 * no expires_at to compute at send time (see
 * conversations.disappearing_messages_seconds = 0, the sentinel this
 * mode is stored under - chosen over a new column on conversations
 * since the existing one is already nullable-and-int with "off"
 * already meaning null, leaving 0 free). This per-message flag is what
 * ConversationRepository::markRead() checks to know which just-read
 * message to purge, since the conversation setting can change after
 * the message was already sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('expire_on_read')->default(false)->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('expire_on_read');
        });
    }
};
