<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// colour theme of the app per person (Preferences > Colour theme, with a phone / desktop preview)
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('user_preferences', 'accent')) {
            Schema::table('user_preferences', function (Blueprint $t) {
                $t->string('accent', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        // the column stays (data safety)
    }
};
