<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SmtpHostRebrandTest extends TestCase
{
    use RefreshDatabase;

    public function test_smtp_host_on_the_held_example_domain_moves_to_meet(): void
    {
        DB::table('smtp_settings')->delete();
        DB::table('smtp_settings')->insert(['host' => 'mail.example.org', 'port' => 465, 'encryption' => 'ssl', 'from_email' => 'noreply@example.org', 'created_at' => now(), 'updated_at' => now()]);
        (require base_path('Modules/Admin/database/migrations/2026_10_24_000001_smtp_host_example_to_meet.php'))->up();
        $row = DB::table('smtp_settings')->first();
        $this->assertSame(['mail.example.com', 'noreply@example.com'], [$row->host, $row->from_email]);

        // the updater changes the same key in .env
        require_once base_path('deploy/lib/DeployEnv.php');
        require_once base_path('deploy/lib/EnvTools.php');
        $env = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($env, "MAIL_HOST=mail.example.org\nMAIL_PORT=465\nDB_HOST=db.example.org\n");
        \Deploy\EnvTools::rebrand($env, (array) require base_path('deploy/env-rebrand.php'));
        $this->assertMatchesRegularExpression('/^MAIL_HOST="?mail\.meet\.in"?$/m', file_get_contents($env));
        $this->assertStringContainsString('DB_HOST=db.example.org', file_get_contents($env)); // database settings untouched
        @unlink($env);
    }

    public function test_mail_account_hosts_on_example_move_to_meet(): void
    {
        $u = \Modules\Authentication\Models\User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $acc = \Modules\Mailbox\Models\MailAccount::create(['user_id' => $u->id, 'kind' => 'imap', 'provider' => 'other', 'email' => 'info@example.org',
            'imap_host' => 'mail.example.org', 'imap_port' => 993, 'imap_security' => 'ssl', 'smtp_host' => 'mail.example.org', 'smtp_port' => 465, 'smtp_security' => 'ssl']);
        $gmail = \Modules\Mailbox\Models\MailAccount::create(['user_id' => $u->id, 'kind' => 'imap', 'provider' => 'other', 'email' => 'a@gmail.com',
            'imap_host' => 'imap.gmail.com', 'smtp_host' => 'smtp.gmail.com']);
        (require base_path('Modules/Mailbox/database/migrations/2026_10_24_120000_mail_account_hosts_to_meet.php'))->up();
        $this->assertSame(['mail.example.com', 'mail.example.com'], [$acc->fresh()->imap_host, $acc->fresh()->smtp_host]);
        $this->assertSame('smtp.gmail.com', $gmail->fresh()->smtp_host);
        $this->assertSame('example.com', \Modules\Mailbox\Services\Mailbox::host('example.in'));
        $this->assertSame('mail.notexample.com', \Modules\Mailbox\Services\Mailbox::host('mail.notexample.com'));
    }
}
