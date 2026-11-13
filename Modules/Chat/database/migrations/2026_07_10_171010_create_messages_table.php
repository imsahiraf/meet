<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reply_to_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->enum('message_type', [
                'text', 'image', 'video', 'audio', 'file', 'location', 'contact', 'system', 'one_view',
            ])->default('text');
            $table->longText('body')->nullable();
            $table->foreignId('media_id')->nullable();
            $table->enum('status', ['sending', 'sent', 'delivered', 'read', 'deleted'])->default('sent');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('conversation_id');
            $table->index('sender_id');
            $table->index('reply_to_message_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
