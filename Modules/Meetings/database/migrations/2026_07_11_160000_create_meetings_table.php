<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DDS-4 "Meetings" table. meeting_type distinguishes FR-121 Instant
 * from FR-122 Scheduled; status follows FR-134's lifecycle
 * (scheduled/waiting/active/locked/ended/cancelled - "starting" and
 * "ending" are transient client states, not persisted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organizer_id')->constrained('users');
            $table->string('meeting_code', 12)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('meeting_type', ['instant', 'scheduled']);
            $table->string('password')->nullable();
            $table->timestamp('scheduled_start')->nullable();
            $table->timestamp('scheduled_end')->nullable();
            $table->string('timezone')->nullable();
            $table->timestamp('actual_start')->nullable();
            $table->timestamp('actual_end')->nullable();
            $table->boolean('guest_enabled')->default(true);
            $table->boolean('waiting_room_enabled')->default(true);
            $table->enum('status', ['scheduled', 'waiting', 'active', 'locked', 'ended', 'cancelled'])->default('scheduled');
            $table->timestamps();

            $table->index(['organizer_id', 'status']);
            $table->index('scheduled_start');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
