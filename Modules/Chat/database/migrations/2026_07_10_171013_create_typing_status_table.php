<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * FR-039: ephemeral, upserted by the polling engine; a scheduled
     * cleanup job (Phase 8 scheduler) removes stale rows past the
     * configured timeout.
     */
    public function up(): void
    {
        Schema::create('typing_status', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['typing', 'recording_audio', 'uploading_file'])->default('typing');
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['conversation_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('typing_status');
    }
};
