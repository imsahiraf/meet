<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-party WebRTC signaling for meetings - not enumerated in any
 * doc (TDS-5 is 1:1-only and buckets "Meeting Rooms" under Future
 * Enhancements; RAS-4 has no meeting-scoped offer/answer/ice routes).
 * This mirrors the existing 1:1 Calls signaling_messages table
 * (DDS-4) exactly, but keyed by meeting_id + sender/receiver pair, to
 * support full-mesh negotiation: each participant pair exchanges its
 * own offer/answer, polled independently, consistent with this app's
 * no-websocket/Node architecture (SAD Sec.7/15) and the documented
 * "no large-group video" non-goal (Project Vision Doc) - mesh only
 * makes sense for the small participant counts this app targets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('sender_participant_id')->constrained('meeting_participants')->cascadeOnDelete();
            $table->foreignId('receiver_participant_id')->constrained('meeting_participants')->cascadeOnDelete();
            $table->enum('signal_type', ['offer', 'answer']);
            $table->longText('payload');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['receiver_participant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_signals');
    }
};
