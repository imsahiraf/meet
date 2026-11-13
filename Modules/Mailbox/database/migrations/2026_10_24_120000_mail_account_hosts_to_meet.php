<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Mailbox\Services\Mailbox;

/* Mail accounts added with mail.example.org (registrar hold, no longer resolves) move to mail.example.com - same server. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mail_accounts')) {
            return;
        }
        foreach (DB::table('mail_accounts')->get(['id', 'imap_host', 'smtp_host']) as $row) {
            $imap = $row->imap_host === null ? null : Mailbox::host($row->imap_host);
            $smtp = $row->smtp_host === null ? null : Mailbox::host($row->smtp_host);
            if ($imap !== $row->imap_host || $smtp !== $row->smtp_host) {
                DB::table('mail_accounts')->where('id', $row->id)->update(['imap_host' => $imap, 'smtp_host' => $smtp, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
    }
};
