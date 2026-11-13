<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guest-created meetings (no login) have no organizer. Raw SQL/manual
 * rebuild instead of ->nullable()->change() to avoid a doctrine/dbal
 * dependency just for this one column - MySQL (production) supports a
 * direct MODIFY; SQLite (tests only) has no ALTER COLUMN at all, so
 * that branch rebuilds the table (empty at this point in migration
 * order either way).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->rebuildForSqlite(nullable: true);

            return;
        }

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropForeign(['organizer_id']);
        });

        DB::statement('ALTER TABLE meetings MODIFY organizer_id BIGINT UNSIGNED NULL');

        Schema::table('meetings', function (Blueprint $table) {
            $table->foreign('organizer_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->rebuildForSqlite(nullable: false);

            return;
        }

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropForeign(['organizer_id']);
        });

        DB::statement('ALTER TABLE meetings MODIFY organizer_id BIGINT UNSIGNED NOT NULL');

        Schema::table('meetings', function (Blueprint $table) {
            $table->foreign('organizer_id')->references('id')->on('users');
        });
    }

    /**
     * Builds a fresh "meetings_new" table (a name nothing else points
     * at yet), copies data across, drops the original "meetings", then
     * renames "meetings_new" to "meetings". Deliberately NOT renaming
     * the original table first - SQLite >= 3.25 auto-rewrites every
     * OTHER table's FK definitions that reference a renamed table's
     * old name, which would leave meeting_settings/meeting_chat/etc.
     * pointing at a name that no longer exists once the temp table is
     * dropped. Renaming meetings_new (nothing references it) avoids
     * that rewrite entirely - other tables' existing "REFERENCES
     * meetings(id)" text simply resolves once "meetings" exists again.
     */
    private function rebuildForSqlite(bool $nullable): void
    {
        Schema::create('meetings_new', function (Blueprint $table) use ($nullable) {
            $table->id();
            $table->uuid('uuid')->unique();
            $organizer = $table->foreignId('organizer_id');

            if ($nullable) {
                $organizer->nullable()->constrained('users')->nullOnDelete();
            } else {
                $organizer->constrained('users');
            }
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

        DB::statement('INSERT INTO meetings_new SELECT * FROM meetings');
        Schema::drop('meetings');
        Schema::rename('meetings_new', 'meetings');
    }
};
