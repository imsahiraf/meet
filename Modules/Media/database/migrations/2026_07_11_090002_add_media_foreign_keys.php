<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * messages.media_id and group_media.media_id were created as plain
 * unsigned columns (the Media module didn't exist yet at that point in
 * the build) - now that it does, wire up the real FK constraints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreign('media_id')->references('id')->on('media')->nullOnDelete();
        });

        Schema::table('group_media', function (Blueprint $table) {
            $table->foreign('media_id')->references('id')->on('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['media_id']);
        });

        Schema::table('group_media', function (Blueprint $table) {
            $table->dropForeign(['media_id']);
        });
    }
};
