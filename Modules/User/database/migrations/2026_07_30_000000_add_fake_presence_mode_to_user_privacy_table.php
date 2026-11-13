<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the existing Super-Access "custom last seen" override (see
 * Documentation/SUPER.md) - which only ever forced a fake OFFLINE
 * status at a manually-typed timestamp - with an "always online" mode
 * that needs no timestamp at all. A Super Access user who just wants
 * to always show as online had to keep re-typing a future datetime
 * into fake_last_seen_at, which doesn't even mean "online," it means
 * "offline since this time." 'always_online' reads live from
 * presence at request time and needs no stored value; 'custom_offline'
 * is the pre-existing fake_last_seen_at behavior, unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_privacy', function (Blueprint $table) {
            $table->enum('fake_presence_mode', ['real', 'always_online', 'custom_offline'])
                ->default('real')
                ->after('fake_last_seen_at');
        });

        DB::table('user_privacy')->whereNotNull('fake_last_seen_at')->update(['fake_presence_mode' => 'custom_offline']);
    }

    public function down(): void
    {
        Schema::table('user_privacy', function (Blueprint $table) {
            $table->dropColumn('fake_presence_mode');
        });
    }
};
