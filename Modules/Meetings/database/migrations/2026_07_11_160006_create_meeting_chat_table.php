<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DDS-4 "Meeting Chat" (1:1 with meetings) - a thin pointer to a
 * 'meeting'-type conversation row in the Chat module. FR-129's
 * feature list (reactions, reply, search, file sharing) is served
 * entirely by the existing Chat engine; no separate message table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_chat', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('meeting_id')->unique()->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_chat');
    }
};
