<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nothing identified a push_subscriptions row as belonging to a
 * particular device/browser at all - a Settings-page "your devices"
 * list would show nothing but an opaque endpoint URL without this.
 * Same low-effort approach user_sessions.browser already uses (a raw
 * truncated User-Agent string, no real parsing library).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->string('user_agent', 150)->nullable()->after('auth_token');
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropColumn('user_agent');
        });
    }
};
