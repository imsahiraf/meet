<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super-access-only "custom last seen" override - see Documentation/SUPER.md.
 * Gated on the existing users.one_view_super_access flag rather than a
 * new separate grantable permission (this app treats "Super Access" as
 * one privileged status unlocking several special behaviors, not a
 * permission per capability).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_privacy', function (Blueprint $table) {
            $table->timestamp('fake_last_seen_at')->nullable()->after('last_seen_visibility');
        });
    }

    public function down(): void
    {
        Schema::table('user_privacy', function (Blueprint $table) {
            $table->dropColumn('fake_last_seen_at');
        });
    }
};
