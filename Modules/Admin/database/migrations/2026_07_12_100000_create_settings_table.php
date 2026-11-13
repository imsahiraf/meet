<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// DDS-6 TABLE 6 "settings" - the general key/value registry backing
// every "Admin Configurable" setting referenced across the SRS
// (retention, session timeout, login attempts, polling interval,
// meeting/call defaults, etc.) that doesn't have its own dedicated
// table (branding/SMTP/feature flags do - see their own migrations).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key')->unique();
            $table->text('setting_value')->nullable();
            $table->string('category', 100)->index();
            $table->enum('data_type', ['string', 'integer', 'boolean', 'json'])->default('string');
            $table->boolean('is_editable')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
