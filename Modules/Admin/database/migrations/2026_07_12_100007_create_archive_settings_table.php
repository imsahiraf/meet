<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS-21 FR-350/FR-351 - archive recipient + password. Single-row
 * settings table (mirrors branding_settings/smtp_settings). The
 * password column is `encrypted` at the model layer (never plaintext
 * at rest, never logged, per FR-351) - the literal default
 * "WitS@123" from the informal Basic.prompt is only ever written
 * through that encrypted cast, never hardcoded elsewhere in the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archive_settings', function (Blueprint $table) {
            $table->id();
            $table->string('recipient_email')->default('private@example.org');
            $table->text('archive_password');
            $table->boolean('password_protection_enabled')->default(true);
            $table->enum('schedule_frequency', ['daily', 'weekly', 'monthly', 'manual_only'])->default('weekly');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_settings');
    }
};
