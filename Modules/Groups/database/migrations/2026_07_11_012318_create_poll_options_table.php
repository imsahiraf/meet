<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Not enumerated in DDS-3 (which jumps straight from group_polls to
 * poll_votes), but poll_votes.option_id has to reference something -
 * inferred here the same way earlier DDS-2 gaps were inferred, from the
 * business rules ("Single Choice / Multiple Choice", "View Results" in
 * SRS FR-082) rather than invented from nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poll_options', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('poll_id')->constrained('group_polls')->cascadeOnDelete();
            $table->string('option_text');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('poll_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poll_options');
    }
};
