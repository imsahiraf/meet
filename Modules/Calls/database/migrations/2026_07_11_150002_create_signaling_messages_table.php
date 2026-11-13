<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Polling-based signaling exchange (SRS FR-096: "Laravel APIs +
 * Polling. No Node.js required."). The client tracks the highest
 * signal id it has already consumed and polls with ?after=, the same
 * pattern already proven correct for Chat message polling - not the
 * processed_at mutation DDS-4 also lists, which is redundant with that
 * and harder to get right under concurrent polling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signaling_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('call_id')->constrained('calls')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users');
            $table->foreignId('receiver_id')->constrained('users');
            $table->enum('signal_type', ['offer', 'answer']);
            $table->longText('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['call_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signaling_messages');
    }
};
