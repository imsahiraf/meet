<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A call can now end with more than two people in it, so the history
 * row needs to say so - "Voice call · 03:12" reads wrong for something
 * that was actually a four-way call. caller_id/receiver_id stay as the
 * originating pair (see the call_participants migration's data-shape
 * decision); this is the one extra fact the history UI needs to render
 * a group call honestly. Defaults to 2 so every pre-existing row keeps
 * describing itself correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_history', function (Blueprint $table) {
            $table->unsignedSmallInteger('participant_count')->default(2)->after('receiver_id');
        });
    }

    public function down(): void
    {
        Schema::table('call_history', function (Blueprint $table) {
            $table->dropColumn('participant_count');
        });
    }
};
