<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DDS-6 SMTP settings. `password` is stored via Laravel's `encrypted`
 * cast (SettingsService/model, not this migration) - FR-201/RAS-6
 * both require SMTP credentials never appear in plaintext at rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smtp_settings', function (Blueprint $table) {
            $table->id();
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->enum('encryption', ['none', 'tls', 'ssl'])->default('tls');
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_succeeded')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smtp_settings');
    }
};
