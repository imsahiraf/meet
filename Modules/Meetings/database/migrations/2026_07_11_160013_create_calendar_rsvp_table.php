<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// FR-169 - one row per invited attendee's RSVP for a calendar event.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_rsvp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['pending', 'accepted', 'declined', 'tentative'])->default('pending');
            $table->timestamps();

            $table->unique(['calendar_event_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_rsvp');
    }
};
