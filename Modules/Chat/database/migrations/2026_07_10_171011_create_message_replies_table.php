<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * FR-043: a stable quoted-preview snapshot for a reply, distinct from
     * `messages.reply_to_message_id` (the live FK link) so the preview
     * shown to the replier survives later edits/deletion of the original.
     */
    public function up(): void
    {
        Schema::create('message_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('replied_to_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('replied_to_sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('replied_to_type', 20)->nullable();
            $table->string('replied_to_preview', 255)->nullable();
            $table->timestamps();

            $table->unique('message_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_replies');
    }
};
