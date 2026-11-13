<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Calls no longer ever create a Meeting. "Add member" used to end the
 * 1:1 call outright and redirect everyone into a freshly-created
 * instant Meeting, with escalated_to_meeting_id as the breadcrumb the
 * other party's poll followed - that whole mechanism is gone now that
 * a call is natively N-party (see the call_participants migration and
 * CallService::addParticipant()): adding someone keeps the SAME call
 * alive and just adds a participant row, so there is nothing to
 * escalate to and nowhere to navigate.
 *
 * media_state goes with it: per-person audio/video flags now live on
 * call_participants (real boolean columns per user), not in a JSON blob
 * keyed by the strings 'caller'/'receiver' - which cannot describe a
 * third person at all. The values were copied across by the previous
 * migration's backfill before this drops the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->rebuildForSqlite();

            return;
        }

        Schema::table('calls', function (Blueprint $table) {
            if (Schema::hasColumn('calls', 'escalated_to_meeting_id')) {
                $table->dropConstrainedForeignId('escalated_to_meeting_id');
            }
        });

        Schema::table('calls', function (Blueprint $table) {
            if (Schema::hasColumn('calls', 'media_state')) {
                $table->dropColumn('media_state');
            }
        });
    }

    /**
     * SQLite's ALTER TABLE ... DROP COLUMN refuses to drop a column that
     * a foreign key definition still mentions, and SQLite has no
     * DROP CONSTRAINT to remove that definition first - so
     * escalated_to_meeting_id cannot be dropped in place there at all
     * (confirmed by a real failing test run: "error in table calls after
     * drop column: unknown column escalated_to_meeting_id in foreign key
     * definition"). The standard SQLite answer, already used twice in
     * this module for widening signaling_messages.signal_type, is a full
     * table rebuild: create the table as it should now look, copy every
     * row across, swap it in.
     *
     * This matters rather than being SQLite trivia: the test suite runs
     * on SQLite while dev/production run MySQL, and this project has been
     * bitten before by a migration that only really worked on one of the
     * two.
     */
    private function rebuildForSqlite(): void
    {
        if (! Schema::hasColumn('calls', 'escalated_to_meeting_id') && ! Schema::hasColumn('calls', 'media_state')) {
            return;
        }

        Schema::create('calls_rebuild', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('caller_id')->constrained('users');
            $table->foreignId('receiver_id')->constrained('users');
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->enum('call_type', ['voice', 'video']);
            $table->enum('call_status', ['ringing', 'accepted', 'rejected', 'missed', 'cancelled', 'ended'])->default('ringing');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['caller_id', 'call_status']);
            $table->index(['receiver_id', 'call_status']);
        });

        // Explicit column list, not SELECT * - SQLite ignores ->after()
        // so the dropped columns were physically appended at the end,
        // making positional copying wrong.
        $columns = 'id, uuid, caller_id, receiver_id, conversation_id, call_type, call_status, started_at, answered_at, ended_at, duration_seconds, created_at, updated_at';
        DB::statement("INSERT INTO calls_rebuild ({$columns}) SELECT {$columns} FROM calls");

        // call_participants/signaling_messages/etc. carry FKs to
        // calls.id; the ids are preserved verbatim above, and SQLite
        // resolves those FKs by table name, so swapping the table keeps
        // every child row pointing at the same call it always did.
        Schema::disableForeignKeyConstraints();
        Schema::drop('calls');
        Schema::rename('calls_rebuild', 'calls');
        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->json('media_state')->nullable()->after('call_status');
        });

        Schema::table('calls', function (Blueprint $table) {
            $table->foreignId('escalated_to_meeting_id')->nullable()->after('media_state')->constrained('meetings')->nullOnDelete();
        });
    }
};
