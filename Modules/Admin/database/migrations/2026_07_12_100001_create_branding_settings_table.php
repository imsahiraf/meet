<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// DDS-6 TABLE 7 "branding_settings". Single-row table (no per-tenant
// multi-brand requirement is documented) enforced by the seeder
// always using id=1.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branding_settings', function (Blueprint $table) {
            $table->id();
            $table->string('app_name')->default('Meet');
            $table->string('company_name')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->string('theme_color', 16)->default('#4f46e5');
            $table->string('login_background_path')->nullable();
            $table->text('footer_text')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branding_settings');
    }
};
