<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DDS-7 TABLE 2 "activity_logs" - general user-activity feed (Login,
 * Logout, Message Sent, Meeting Joined, File Uploaded, ...), distinct
 * from `audit_logs` which is specifically administrative
 * create/update/delete actions with before/after value diffs. No
 * literal column list is given for this table in the docs (unlike
 * audit_logs) - this shape mirrors it minus the diff columns, which
 * activity events don't need.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 100);
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
