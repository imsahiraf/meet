<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* example.org is on registrar hold: an SMTP host on it (mail.example.org) no longer resolves. Same server as example.com. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('smtp_settings')) {
            return;
        }
        foreach (DB::table('smtp_settings')->get(['id', 'host', 'from_email']) as $row) {
            $host = preg_replace('/(^|\.)example\.(com|in)$/i', '$1example.com', (string) $row->host);
            $from = preg_replace('/@example\.(com|in)$/i', '@example.com', (string) $row->from_email);
            if ($host !== $row->host || $from !== $row->from_email) {
                DB::table('smtp_settings')->where('id', $row->id)->update(['host' => $host, 'from_email' => $from ?: null, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
    }
};
