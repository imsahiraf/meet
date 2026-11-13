<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_privacy', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->enum('last_seen_visibility', ['everyone', 'contacts', 'nobody'])->default('everyone');
            $table->enum('profile_photo_visibility', ['everyone', 'contacts', 'nobody'])->default('everyone');
            $table->enum('about_visibility', ['everyone', 'contacts', 'nobody'])->default('everyone');
            $table->boolean('read_receipts_enabled')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_privacy');
    }
};
