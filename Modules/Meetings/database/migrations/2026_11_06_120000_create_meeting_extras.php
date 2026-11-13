<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-11-06 (Meet backlog): live captions, recording, breakout rooms and the meeting summary.
 * meeting_events is a meeting-wide stream (the signals table is per pair): captions, "recording on / off", "go to breakout room",
 * "come back". Captions double as the transcript the summary is made from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $t->foreignId('participant_id')->nullable()->constrained('meeting_participants')->nullOnDelete();
            $t->string('type', 20);
            $t->text('payload');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['meeting_id', 'id']);
        });
        Schema::create('meeting_recordings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('path');
            $t->unsignedBigInteger('size')->default(0);
            $t->unsignedInteger('seconds')->default(0);
            $t->timestamps();
        });
        Schema::table('meetings', function (Blueprint $t) {
            $t->foreignId('parent_meeting_id')->nullable()->after('id')->constrained('meetings')->nullOnDelete();
            $t->string('breakout_name', 60)->nullable()->after('title');
            $t->longText('summary')->nullable();
            $t->timestamp('summary_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $t) {
            $t->dropConstrainedForeignId('parent_meeting_id');
            $t->dropColumn(['breakout_name', 'summary', 'summary_at']);
        });
        Schema::dropIfExists('meeting_recordings');
        Schema::dropIfExists('meeting_events');
    }
};
