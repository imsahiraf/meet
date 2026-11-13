<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "Super Access" auto-download exception the One View migration
 * docblock flagged as deferred (undocumented beyond its name) -
 * concretely: an admin-grantable per-user flag. A Super Access viewer's
 * first open of a One View message force-downloads the file
 * (Content-Disposition: attachment) instead of an inline preview; the
 * server copy is still deleted immediately after either way (see
 * OneViewService/MediaController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('one_view_super_access')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('one_view_super_access');
        });
    }
};
