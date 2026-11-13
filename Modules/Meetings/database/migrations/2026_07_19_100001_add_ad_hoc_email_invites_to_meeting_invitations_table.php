<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ad-hoc email invitees (not yet registered users) - either
 * invited_user_id or invited_email is set, mirroring the
 * meeting_participants user_id/guest_session_id "one of two" pattern.
 * Nothing else FKs to meeting_invitations, so - unlike the
 * organizer_id migration - no cross-table FK-rewrite risk on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_invitations', function (Blueprint $table) {
            $table->string('invited_email')->nullable()->after('invited_user_id');
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->rebuildForSqlite(nullable: true);

            return;
        }

        Schema::table('meeting_invitations', function (Blueprint $table) {
            $table->dropForeign(['invited_user_id']);
        });

        DB::statement('ALTER TABLE meeting_invitations MODIFY invited_user_id BIGINT UNSIGNED NULL');

        Schema::table('meeting_invitations', function (Blueprint $table) {
            $table->foreign('invited_user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->rebuildForSqlite(nullable: false);

            return;
        }

        Schema::table('meeting_invitations', function (Blueprint $table) {
            $table->dropForeign(['invited_user_id']);
        });

        DB::statement('ALTER TABLE meeting_invitations MODIFY invited_user_id BIGINT UNSIGNED NOT NULL');

        Schema::table('meeting_invitations', function (Blueprint $table) {
            $table->foreign('invited_user_id')->references('id')->on('users');
        });

        Schema::table('meeting_invitations', function (Blueprint $table) {
            $table->dropColumn('invited_email');
        });
    }

    private function rebuildForSqlite(bool $nullable): void
    {
        Schema::create('meeting_invitations_new', function (Blueprint $table) use ($nullable) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users');
            $invitedUser = $table->foreignId('invited_user_id');
            $nullable ? $invitedUser->nullable()->constrained('users') : $invitedUser->constrained('users');

            if ($nullable) {
                $table->string('invited_email')->nullable();
            }

            $table->enum('invitation_status', ['pending', 'accepted', 'declined', 'tentative'])->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamps();

            $table->unique(['meeting_id', 'invited_user_id']);
        });

        $columns = 'id, uuid, meeting_id, invited_by, invited_user_id, invitation_status, sent_at, accepted_at, declined_at, created_at, updated_at';

        DB::statement("INSERT INTO meeting_invitations_new ({$columns}) SELECT {$columns} FROM meeting_invitations");
        Schema::drop('meeting_invitations');
        Schema::rename('meeting_invitations_new', 'meeting_invitations');
    }
};
