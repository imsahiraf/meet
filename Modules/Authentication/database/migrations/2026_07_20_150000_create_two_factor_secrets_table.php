<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A separate table (this codebase's per-concept convention, matching
 * ArchiveSetting/SmtpSetting's own 'encrypted' cast precedent) rather
 * than bolting columns onto users. confirmed_at null means "enabled
 * but not yet confirmed with a real code" - never treated as active
 * 2FA until a real code proves the user actually scanned it right.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('two_factor_secrets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('secret');
            $table->text('recovery_codes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_secrets');
    }
};
