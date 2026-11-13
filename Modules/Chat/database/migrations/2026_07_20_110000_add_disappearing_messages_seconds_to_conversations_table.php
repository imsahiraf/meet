<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp-style disappearing messages - conversation-wide (unlike
 * chat_settings, which is per-user/per-conversation), so it belongs
 * directly on conversations, not chat_settings. Null/0 means off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedInteger('disappearing_messages_seconds')->nullable()->after('is_archived');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('disappearing_messages_seconds');
        });
    }
};
