<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-127/FR-320 - one row per join attempt while the waiting room is
 * enabled. participant_type + participant_reference covers both
 * registered users and guests without two nullable FK columns, since
 * a guest requesting entry doesn't have a meeting_guest_sessions row
 * yet (that's only created once admitted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_waiting_room', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->enum('participant_type', ['user', 'guest']);
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('guest_name')->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('admitted_at')->nullable();
            $table->enum('status', ['waiting', 'admitted', 'rejected'])->default('waiting');
            $table->timestamps();

            $table->index(['meeting_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_waiting_room');
    }
};
