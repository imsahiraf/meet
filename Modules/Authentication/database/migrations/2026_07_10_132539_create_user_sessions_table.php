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
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('session_id')->unique();
            $table->string('ip_address', 45)->nullable();
            $table->string('device_name', 150)->nullable();
            $table->string('browser', 100)->nullable();
            $table->string('operating_system', 100)->nullable();
            $table->string('platform', 50)->nullable();
            $table->timestamp('login_at')->useCurrent();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('logout_at')->nullable();
            $table->enum('status', ['active', 'expired', 'logged_out', 'terminated'])->default('active');
            $table->timestamps();

            $table->index('status');
            $table->index('login_at');
            $table->index('last_activity_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
