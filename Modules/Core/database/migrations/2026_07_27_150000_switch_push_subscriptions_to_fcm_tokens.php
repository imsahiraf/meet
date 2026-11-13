<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * All push notifications now go through Firebase Cloud Messaging, per
 * direct instruction - the raw Web Push endpoint/p256dh/auth triple
 * (minishlink/web-push) is replaced by a single FCM registration
 * token; Firebase's own JS SDK obtains it (still via the same
 * underlying browser Push API/service worker under the hood - this
 * app's existing push/notificationclick handlers in
 * public/service-worker.js are untouched) and Firebase's Admin SDK is
 * what the server calls to actually send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['endpoint', 'p256dh_key', 'auth_token']);
            $table->text('fcm_token')->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropColumn('fcm_token');
            $table->text('endpoint')->after('user_id');
            $table->string('p256dh_key')->after('endpoint');
            $table->string('auth_token')->after('p256dh_key');
        });
    }
};
