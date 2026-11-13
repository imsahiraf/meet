<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * FR-042 "Delete for Me": per-user hiding of a message that still
     * exists for everyone else. "Delete for Everyone" instead uses
     * `messages.deleted_at` / `messages.status = deleted`.
     */
    public function up(): void
    {
        Schema::create('deleted_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('deleted_at')->useCurrent();

            $table->unique(['message_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deleted_messages');
    }
};
