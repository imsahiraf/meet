<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * FR-051 - a small catalog of system-default wallpapers plus any
     * custom ones a user has configured.
     */
    public function up(): void
    {
        Schema::create('chat_wallpapers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->enum('type', ['color', 'image', 'default'])->default('default');
            $table->string('value')->nullable();
            $table->boolean('is_system_default')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_wallpapers');
    }
};
