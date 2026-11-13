<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DDS-4 lists 'ringing, accepted, rejected, missed, ended' as the
 * call_status enum; 'cancelled' (caller hangs up before an answer) is
 * added since SRS FR-094's Call History filters explicitly include it
 * as its own status, distinct from both 'rejected' and 'missed'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('caller_id')->constrained('users');
            $table->foreignId('receiver_id')->constrained('users');
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->enum('call_type', ['voice', 'video']);
            $table->enum('call_status', ['ringing', 'accepted', 'rejected', 'missed', 'cancelled', 'ended'])->default('ringing');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['caller_id', 'call_status']);
            $table->index(['receiver_id', 'call_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
