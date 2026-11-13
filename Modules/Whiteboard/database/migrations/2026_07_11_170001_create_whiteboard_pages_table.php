<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// FR-149 - multiple pages per whiteboard, ordered by page_number.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whiteboard_pages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('whiteboard_id')->constrained('whiteboards')->cascadeOnDelete();
            $table->unsignedInteger('page_number');
            $table->string('name')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['whiteboard_id', 'page_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whiteboard_pages');
    }
};
