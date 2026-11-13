<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-147 - a global (whiteboard-wide, not per-user) undo/redo
 * operation log, consistent with a shared canvas. `is_undone` marks
 * entries currently reversed so redo can find the next one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whiteboard_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whiteboard_id')->constrained('whiteboards')->cascadeOnDelete();
            $table->foreignId('whiteboard_object_id')->constrained('whiteboard_objects')->cascadeOnDelete();
            $table->enum('action', ['create', 'update', 'delete']);
            $table->json('previous_state')->nullable();
            $table->json('new_state')->nullable();
            $table->foreignId('actor_id')->constrained('users');
            $table->boolean('is_undone')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['whiteboard_id', 'is_undone', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whiteboard_history');
    }
};
