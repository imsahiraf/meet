<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_statistics', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('call_id')->constrained('calls')->cascadeOnDelete();
            $table->decimal('packet_loss_percent', 5, 2)->nullable();
            $table->unsignedInteger('avg_latency_ms')->nullable();
            $table->unsignedInteger('jitter_ms')->nullable();
            $table->unsignedInteger('connection_time_ms')->nullable();
            $table->unsignedInteger('disconnect_count')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index('call_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_statistics');
    }
};
