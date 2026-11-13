<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * FR-040: the privacy-gated "read" confirmation shown to the sender -
     * distinct from `message_status`, which always records delivery/read
     * internally regardless of the recipient's read-receipt privacy
     * setting (user_privacy.read_receipts_enabled).
     */
    public function up(): void
    {
        Schema::create('read_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at')->useCurrent();

            $table->unique(['message_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('read_receipts');
    }
};
