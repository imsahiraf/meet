<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS FR-317/FR-321 - a guest never registers; identity is just a
 * display name plus a secure session token embedded in their join
 * link. Default 15-minute expiry, admin-configurable via config().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_guest_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->string('guest_name');
            $table->string('session_token', 64)->unique();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('expires_at');
            $table->enum('status', ['pending', 'active', 'expired', 'removed'])->default('pending');
            $table->timestamps();

            $table->index(['meeting_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_guest_sessions');
    }
};
