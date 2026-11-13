<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_history', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('call_id')->unique()->constrained('calls')->cascadeOnDelete();
            $table->foreignId('caller_id')->constrained('users');
            $table->foreignId('receiver_id')->constrained('users');
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->string('end_reason', 50);
            $table->string('network_quality', 20)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('caller_id');
            $table->index('receiver_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_history');
    }
};
