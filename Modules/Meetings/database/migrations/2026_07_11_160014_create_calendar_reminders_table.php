<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// FR-166 - reminder channels are Email + In-App in v1 (Push/SMS are future).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('remind_before_minutes');
            $table->enum('channel', ['email', 'in_app'])->default('in_app');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['calendar_event_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_reminders');
    }
};
