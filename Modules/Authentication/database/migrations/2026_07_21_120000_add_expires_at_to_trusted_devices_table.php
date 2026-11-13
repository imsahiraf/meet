<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The original schema only had `status` (active/revoked/expired) and
 * `trusted_at`, with no way to represent "trust this device for 30
 * days" as a real, queryable deadline - a background job would have
 * had to compute + flip `status` to 'expired' itself. An explicit
 * `expires_at` lets the 2FA gate check "is this still valid right
 * now" with a single WHERE clause instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trusted_devices', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('trusted_at');
        });
    }

    public function down(): void
    {
        Schema::table('trusted_devices', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
