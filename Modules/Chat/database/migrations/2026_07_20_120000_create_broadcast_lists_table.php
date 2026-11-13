<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp-style broadcast lists - a pure distribution list, not a
 * shared conversation like Groups (no conversation_id here). Sending
 * fans out to N separate 1:1 conversations via the existing
 * startPrivateConversation()/MessageService::send() path, so replies
 * stay private per-recipient with no new isolation mechanism needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcast_lists', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 150);
            $table->timestamps();

            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_lists');
    }
};
