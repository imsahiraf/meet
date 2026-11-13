<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DDS-4 "Meeting Invitations" - one row per invited registered user
 * (FR-123/FR-168/FR-169). Guest-only meetings never populate this
 * table (FR-167: "Guest-only meetings shall not include ICS
 * invitations").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_invitations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users');
            $table->foreignId('invited_user_id')->constrained('users');
            $table->enum('invitation_status', ['pending', 'accepted', 'declined', 'tentative'])->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamps();

            $table->unique(['meeting_id', 'invited_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_invitations');
    }
};
