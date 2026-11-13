<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Read receipts (Mail Hub parity, 2026-11-11): what was asked when sending and who read it / why it bounced
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mail_messages') && !Schema::hasColumn('mail_messages', 'send_meta')) {
            Schema::table('mail_messages', fn (Blueprint $t) => $t->json('send_meta')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mail_messages', 'send_meta')) {
            Schema::table('mail_messages', fn (Blueprint $t) => $t->dropColumn('send_meta'));
        }
    }
};
