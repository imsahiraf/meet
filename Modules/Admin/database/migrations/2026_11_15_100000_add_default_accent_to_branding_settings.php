<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Themes (owner 2026-11-15): the colour theme everybody gets until they pick their own (a preset, or "brand" = the theme colour).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branding_settings') && !Schema::hasColumn('branding_settings', 'default_accent')) {
            Schema::table('branding_settings', fn (Blueprint $t) => $t->string('default_accent', 20)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('branding_settings', 'default_accent')) {
            Schema::table('branding_settings', fn (Blueprint $t) => $t->dropColumn('default_accent'));
        }
    }
};
