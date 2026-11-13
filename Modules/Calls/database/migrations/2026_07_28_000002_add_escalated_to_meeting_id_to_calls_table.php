<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Add member" on an active 1:1 call escalates it into a full Meeting
 * (reusing the Meetings module's already-built N-way mesh/lock/
 * reactions/screen-share instead of duplicating that infrastructure
 * inside the pair-only Calls module) - this column is how the other
 * party's still-open call poll learns where to follow the caller to,
 * instead of just seeing the call end and being left behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->foreignId('escalated_to_meeting_id')->nullable()->after('media_state')->constrained('meetings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('escalated_to_meeting_id');
        });
    }
};
