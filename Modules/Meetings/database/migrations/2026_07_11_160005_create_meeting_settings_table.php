<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DDS-4 "Meeting Settings" (1:1 with meetings) - covers FR-130/FR-133/
 * FR-142's per-meeting feature toggles and permission modes.
 * meeting_permissions' "Mute Others/Remove Participants" capabilities
 * from the same DDS chapter are implemented as plain role checks
 * (host/co_host) in the service layer instead of a separate ACL
 * table - DDS never enumerates columns for that table, and a
 * dedicated per-participant grant table would be speculative given
 * every listed capability maps directly onto the existing role enum
 * or one of the settings below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->unique()->constrained('meetings')->cascadeOnDelete();
            $table->boolean('chat_enabled')->default(true);
            $table->boolean('whiteboard_enabled')->default(true);
            $table->boolean('screen_share_enabled')->default(true);
            $table->enum('whiteboard_permission', ['host_only', 'host_and_co_host', 'all_participants', 'view_only'])->default('all_participants');
            $table->enum('screen_share_permission', ['host_only', 'all_participants'])->default('host_only');
            $table->boolean('auto_mute_on_join')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_settings');
    }
};
