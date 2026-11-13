<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phone mail apps (Gmail app, iPhone Mail, Samsung Email, Outlook) through Exchange ActiveSync over HTTPS - works on shared
 * hosting where no IMAP / SMTP server can run:
 *  - mail_app_passwords: a separate password per phone (shown once, revocable); the normal login password is never used;
 *  - mail_eas_devices: the phones that signed in;
 *  - mail_eas_states: what each phone already has per folder (sync key + list of message ids and flags).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mail_app_passwords')) {
            Schema::create('mail_app_passwords', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('org_id')->nullable()->index();
                $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $t->string('name', 80);
                $t->string('hash', 255);
                $t->string('hint', 8); // last characters, to recognise it in the list
                $t->timestamp('last_used_at')->nullable();
                $t->string('last_ip', 45)->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('mail_eas_devices')) {
            Schema::create('mail_eas_devices', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('org_id')->nullable()->index();
                $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $t->unsignedBigInteger('account_id')->nullable(); // null = all the user's accounts together
                $t->unsignedBigInteger('app_password_id')->nullable()->index();
                $t->string('device_id', 64);
                $t->string('device_type', 64)->nullable();
                $t->string('user_agent', 190)->nullable();
                $t->unsignedInteger('policy_key')->nullable();
                $t->unsignedInteger('folder_key')->default(0);
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamps();
                $t->unique(['user_id', 'device_id']);
            });
        }
        if (!Schema::hasTable('mail_eas_states')) {
            Schema::create('mail_eas_states', function (Blueprint $t) {
                $t->id();
                $t->foreignId('device_id')->constrained('mail_eas_devices')->cascadeOnDelete();
                $t->string('collection', 20);
                $t->unsignedInteger('sync_key')->default(0);
                $t->longText('items')->nullable();      // json {message id: flags} the phone has at sync_key
                $t->unsignedInteger('prev_key')->nullable();
                $t->longText('prev_items')->nullable(); // the state before, so a repeated request (lost answer) still works
                $t->unsignedTinyInteger('filter')->default(0);
                $t->timestamps();
                $t->unique(['device_id', 'collection']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_eas_states');
        Schema::dropIfExists('mail_eas_devices');
        Schema::dropIfExists('mail_app_passwords');
    }
};
