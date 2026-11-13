<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// FR-165 - the recurrence rule shared by a series of calendar_events.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->enum('frequency', ['daily', 'weekly', 'monthly', 'yearly']);
            $table->unsignedInteger('interval')->default(1);
            $table->unsignedInteger('occurrences')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_events');
    }
};
