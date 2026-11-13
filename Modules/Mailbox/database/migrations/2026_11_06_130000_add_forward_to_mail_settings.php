<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Mail on the phone without IMAP: a copy of every new mail goes to an outside address of the person (Gmail, Outlook ...). */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('mail_settings', 'forward_to')) {
            Schema::table('mail_settings', fn (Blueprint $t) => $t->string('forward_to', 190)->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('mail_settings', fn (Blueprint $t) => $t->dropColumn('forward_to'));
    }
};
