<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DDS-6 TABLE 12 "pwa_settings" - folded into branding_settings
 * rather than a separate single-row table, since every field it
 * needs (App Name, Short Name, Theme Color, Background Color,
 * Display Mode, Orientation) is either identical to or a close
 * sibling of what branding_settings already stores (FR-257/FR-270).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branding_settings', function (Blueprint $table) {
            $table->string('pwa_short_name', 20)->default('Meet')->after('app_name');
            $table->string('pwa_background_color', 16)->default('#ffffff')->after('theme_color');
            $table->enum('pwa_display_mode', ['standalone', 'fullscreen', 'minimal-ui', 'browser'])->default('standalone')->after('pwa_background_color');
        });
    }

    public function down(): void
    {
        Schema::table('branding_settings', function (Blueprint $table) {
            $table->dropColumn(['pwa_short_name', 'pwa_background_color', 'pwa_display_mode']);
        });
    }
};
