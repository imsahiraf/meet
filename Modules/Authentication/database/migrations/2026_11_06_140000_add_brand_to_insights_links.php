<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** White label: the brand of the person's Insights reseller (name, logo, colour) shown in Meet instead of Meet. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('insights_links', 'brand')) {
            Schema::table('insights_links', fn (Blueprint $t) => $t->text('brand')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('insights_links', fn (Blueprint $t) => $t->dropColumn('brand'));
    }
};
