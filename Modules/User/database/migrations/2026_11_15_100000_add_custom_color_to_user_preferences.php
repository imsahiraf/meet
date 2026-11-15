<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Themes (owner 2026-11-15): a person's own colour (accent "custom") besides the preset colour themes.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('user_preferences', 'custom_color')) {
            Schema::table('user_preferences', fn (Blueprint $t) => $t->string('custom_color', 7)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('user_preferences', 'custom_color')) {
            Schema::table('user_preferences', fn (Blueprint $t) => $t->dropColumn('custom_color'));
        }
    }
};
