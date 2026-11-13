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
        Schema::create('email_verifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('email')->index();
            $table->enum('verification_type', [
                'registration',
                'email_change',
                'administrator',
                'future_manual',
            ])->default('registration');
            $table->timestamp('verified_at')->useCurrent();
            $table->string('verified_ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index('verification_type');
            $table->index('verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_verifications');
    }
};
