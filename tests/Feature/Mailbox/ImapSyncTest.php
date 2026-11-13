<?php

namespace Tests\Feature\Mailbox;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Authentication\Models\User;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Models\MailMessage;
use Modules\Mailbox\Services\ImapSync;
use Tests\TestCase;

class ImapSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_reads_new_mail_once_with_literals_and_flags(): void
    {
        Storage::fake('local');
        $portFile = tempnam(sys_get_temp_dir(), 'imap');
        @unlink($portFile);
        $proc = proc_open([PHP_BINARY, __DIR__ . '/fixtures/fake-imap.php', $portFile], [], $pipes);
        for ($i = 0; $i < 50 && !is_file($portFile); $i++) {
            usleep(100000);
        }
        $port = (int) file_get_contents($portFile);
        $u = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $acc = MailAccount::create(['user_id' => $u->id, 'kind' => 'imap', 'email' => 'asha@example.org', 'imap_host' => '127.0.0.1', 'imap_port' => $port,
            'imap_security' => 'none', 'smtp_host' => '127.0.0.1', 'smtp_port' => 25, 'smtp_security' => 'none', 'secret' => 'secret']);

        $this->assertSame(2, ImapSync::run($acc));
        $acc->refresh();
        $this->assertNull($acc->last_error);
        $this->assertSame(['First {curly}', 'Second'], MailMessage::orderBy('remote_uid')->pluck('subject')->all());
        $this->assertSame([true, false], MailMessage::orderBy('remote_uid')->pluck('seen')->all(), 'server read state kept');
        $this->assertSame(9, $acc->sync_state['INBOX']['last']);

        // second run: nothing new, no duplicates
        $this->assertSame(0, ImapSync::run($acc->fresh()));
        $this->assertSame(2, MailMessage::count());
        proc_terminate($proc);
        @unlink($portFile);
    }
}
