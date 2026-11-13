<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A restorable soft-delete, matching the rest of the app's convention
 * for destructive-looking actions (Groups already have both a
 * 'deleted' status AND softDeletes(); Users soft-delete on
 * deactivation) - "Delete community" shouldn't be the one place in
 * this app where removal is unrecoverable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communities', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('communities', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
