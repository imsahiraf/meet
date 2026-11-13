<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * FR-044 - links a newly created message (in the destination
     * conversation) back to the message it was forwarded from.
     */
    public function up(): void
    {
        Schema::create('forwarded_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('original_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('forwarded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique('message_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('forwarded_messages');
    }
};
