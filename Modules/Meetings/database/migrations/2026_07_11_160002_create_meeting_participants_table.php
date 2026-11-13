<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DDS-4 "Meeting Participants". Either user_id (registered) or
 * guest_session_id (FR-316) is set, never both - enforced in the
 * service layer, not the schema, since Laravel has no portable
 * "exactly one of" constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_participants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('guest_session_id')->nullable()->constrained('meeting_guest_sessions')->cascadeOnDelete();
            $table->enum('role', ['host', 'co_host', 'presenter', 'participant'])->default('participant');
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('left_at')->nullable();
            $table->boolean('microphone_enabled')->default(true);
            $table->boolean('camera_enabled')->default(false);
            $table->boolean('screen_sharing')->default(false);
            $table->boolean('hand_raised')->default(false);
            $table->timestamps();

            $table->index(['meeting_id', 'user_id']);
            $table->index(['meeting_id', 'left_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_participants');
    }
};
