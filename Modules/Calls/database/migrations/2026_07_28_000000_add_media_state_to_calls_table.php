<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real WhatsApp-style calls show the OTHER party's mute/camera-off
 * state on their own video tile - this app's WebRTC layer never sent
 * that signal at all (turning your own mic/camera off was a purely
 * local UI toggle, invisible to the person you're calling). One JSON
 * column per call (not two new boolean pairs) since it's keyed by
 * caller/receiver rather than a fixed per-user shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->json('media_state')->nullable()->after('call_status');
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropColumn('media_state');
        });
    }
};
