<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// DDS-4 "Meeting Reports" - one row, computed when a meeting ends (FR-135).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->unique()->constrained('meetings')->cascadeOnDelete();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedInteger('total_participants')->default(0);
            $table->unsignedInteger('guest_count')->default(0);
            $table->unsignedInteger('screen_share_seconds')->default(0);
            $table->unsignedInteger('whiteboard_object_count')->default(0);
            $table->unsignedInteger('chat_message_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_reports');
    }
};
