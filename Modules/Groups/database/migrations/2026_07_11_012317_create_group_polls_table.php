<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_polls', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('question');
            $table->boolean('allow_multiple_votes')->default(false);
            $table->timestamp('closes_at')->nullable();
            $table->timestamps();

            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_polls');
    }
};
