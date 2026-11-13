<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-151 - PNG/JPG/PDF export. The browser renders the canvas to a
 * blob client-side (Canvas API) and uploads it through the existing
 * Media engine (Modules\Media) rather than this app standing up a
 * server-side canvas-rendering library it doesn't otherwise need;
 * this row is just the export's record/pointer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whiteboard_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whiteboard_id')->constrained('whiteboards')->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->enum('format', ['png', 'jpg', 'pdf']);
            $table->foreignId('exported_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whiteboard_exports');
    }
};
