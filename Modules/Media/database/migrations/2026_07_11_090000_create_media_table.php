<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('uploader_id')->constrained('users')->cascadeOnDelete();
            $table->string('disk', 50)->default('local');
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');
            $table->enum('media_type', ['image', 'video', 'audio', 'document']);
            $table->boolean('is_voice_note')->default(false);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();

            $table->index('uploader_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
