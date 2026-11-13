<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A short-lived "password already verified, pending a TOTP code"
 * marker - mirrors this codebase's existing EmailOtp/PasswordResetToken
 * convention (a DB row + expiry + status) rather than a signed URL or
 * cache token, since that's the established pattern for this exact
 * kind of "prove a fact, get a short-lived credential" step here.
 * "remember" carries the login form's Remember Me choice across the
 * extra hop so it isn't lost once 2FA is involved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('two_factor_challenges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('remember')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_challenges');
    }
};
