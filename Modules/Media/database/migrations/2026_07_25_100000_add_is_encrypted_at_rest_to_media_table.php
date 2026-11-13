<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * View Once files are encrypted at rest (Laravel's app-key-based
 * encryption, not the client-side E2E scheme regular messages use) the
 * moment they're created - this flag tells MediaController whether the
 * bytes on disk need decrypting before they can be streamed/attached.
 * Deliberately server-decryptable (not true E2E) so the existing
 * archive-copy mail (see OneViewService/Documentation/SUPER.md) can
 * still read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->boolean('is_encrypted_at_rest')->default(false)->after('duration_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn('is_encrypted_at_rest');
        });
    }
};
