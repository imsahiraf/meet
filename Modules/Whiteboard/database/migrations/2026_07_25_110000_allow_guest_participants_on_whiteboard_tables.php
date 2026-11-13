<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guests have no `users` row at all, so every whiteboard creator/actor
 * column - previously a hard, non-nullable FK to `users` - needs a
 * guest-compatible alternative. Rather than migrating existing data to
 * a different identity model, each column gets a sibling
 * `..._participant_id` (FK to `meeting_participants`, which already
 * has a nullable `guest_session_id` and is how the rest of this app
 * already represents "whoever is in this meeting, guest or not" - see
 * MeetingParticipant::isGuest()/displayName()). A row is created by
 * either a real user (the original column, unchanged for existing
 * rows) or a guest (the new column) - never both, never neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whiteboards', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->change();
            $table->foreignId('created_by_participant_id')->nullable()->after('created_by')->constrained('meeting_participants')->nullOnDelete();
        });

        Schema::table('whiteboard_objects', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->change();
            $table->foreignId('created_by_participant_id')->nullable()->after('created_by')->constrained('meeting_participants')->nullOnDelete();
        });

        Schema::table('whiteboard_history', function (Blueprint $table) {
            $table->foreignId('actor_id')->nullable()->change();
            $table->foreignId('actor_participant_id')->nullable()->after('actor_id')->constrained('meeting_participants')->nullOnDelete();
        });

        Schema::table('whiteboard_exports', function (Blueprint $table) {
            $table->foreignId('exported_by')->nullable()->change();
            $table->foreignId('exported_by_participant_id')->nullable()->after('exported_by')->constrained('meeting_participants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('whiteboards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_participant_id');
        });
        Schema::table('whiteboard_objects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_participant_id');
        });
        Schema::table('whiteboard_history', function (Blueprint $table) {
            $table->dropConstrainedForeignId('actor_participant_id');
        });
        Schema::table('whiteboard_exports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exported_by_participant_id');
        });
    }
};
