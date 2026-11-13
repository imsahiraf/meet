<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Per-conversation, per-user chat preferences (FR-050 mute duration,
     * FR-051 wallpaper) - complements conversation_members' simple
     * is_muted/is_archived booleans with the richer, timed mute state.
     */
    public function up(): void
    {
        Schema::create('chat_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('wallpaper_id')->nullable();
            // DATETIME (not TIMESTAMP) - "mute forever" stores a
            // far-future date (now()->addYears(100)) that overflows
            // TIMESTAMP's 2038 ceiling.
            $table->dateTime('muted_until')->nullable();
            $table->boolean('notifications_enabled')->default(true);
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_settings');
    }
};
