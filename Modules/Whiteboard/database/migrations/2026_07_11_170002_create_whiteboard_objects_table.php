<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-143/FR-144/FR-146 - one row per drawn/placed object. `data` is a
 * JSON payload of whatever the tool needs (points for pen/line, x/y/
 * width/height for shapes, text content + font, image url) - the
 * schema doesn't try to normalize per-tool geometry into columns
 * since FR-143's tool list is open-ended and DDS-4 never enumerates
 * object columns beyond "Coordinates, Tool, Color, Thickness, Text,
 * Object State, Timestamp" (FR-148), which is exactly this shape.
 * is_deleted is a soft flag (not Laravel's SoftDeletes) so undo can
 * resurrect an object without touching timestamps semantics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whiteboard_objects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('whiteboard_page_id')->constrained('whiteboard_pages')->cascadeOnDelete();
            $table->enum('tool', ['pen', 'line', 'rectangle', 'circle', 'arrow', 'text', 'sticky_note', 'image']);
            $table->json('data');
            $table->string('color', 16)->nullable();
            $table->unsignedInteger('thickness')->nullable();
            $table->unsignedInteger('z_index')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->index(['whiteboard_page_id', 'is_deleted', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whiteboard_objects');
    }
};
