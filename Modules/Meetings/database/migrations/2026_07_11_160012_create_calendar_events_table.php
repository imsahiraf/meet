<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DDS-5 Calendar Engine. A standalone `calendars` (one-per-user)
 * container table is skipped - DDS-5 never enumerates a column
 * beyond "belongs to a user", so "a user's calendar" is just
 * calendar_events where created_by = the user, avoiding an empty
 * pass-through table.
 *
 * meeting_id is this schema's judgment call for the undocumented
 * calendar_events<->meetings relationship (DDS-5 names both tables
 * but never shows the join column) - nullable because non-meeting
 * event types (Reminder/Personal/Holiday/Task, FR-161) don't have one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('meeting_id')->nullable()->constrained('meetings')->cascadeOnDelete();
            $table->enum('event_type', ['meeting', 'reminder', 'personal', 'holiday', 'task']);
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->string('timezone');
            $table->foreignId('recurring_event_id')->nullable()->constrained('recurring_events')->nullOnDelete();
            $table->enum('status', ['draft', 'scheduled', 'active', 'completed', 'cancelled', 'archived'])->default('scheduled');
            $table->timestamps();

            $table->index(['created_by', 'start_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
