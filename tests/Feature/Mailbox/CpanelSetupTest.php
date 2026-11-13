<?php

namespace Tests\Feature\Mailbox;

use Illuminate\Support\Facades\Http;
use Modules\Mailbox\Services\CpanelMail;
use Tests\TestCase;

class CpanelSetupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('HOME=' . sys_get_temp_dir());
        config(['mailbox.domain' => 'example.com', 'services.cpanel.user' => 'meetuser', 'services.cpanel.token' => 'TOKEN123']);
    }

    public function test_catch_all_is_pointed_at_meet_once(): void
    {
        $calls = [];
        Http::fake(function ($request) use (&$calls) {
            $calls[] = $request->url();
            $this->assertSame('cpanel meetuser:TOKEN123', $request->header('Authorization')[0]);
            if (str_contains($request->url(), 'list_default_address')) {
                return Http::response(['status' => 1, 'data' => [['domain' => 'example.com', 'defaultaddress' => ':fail: No such person at this address']]]);
            }

            return Http::response(['status' => 1, 'data' => null]);
        });
        $r = CpanelMail::ensure();
        $this->assertSame('ok', $r['status']);
        $this->assertTrue(collect($calls)->contains(fn ($u) => str_contains($u, '/execute/Email/set_default_address') && str_contains($u, 'fwdopt=pipe') && str_contains($u, 'meet-mail-pipe.php')));
        $file = sys_get_temp_dir() . '/meet-mail-pipe.php';
        $script = (string) file_get_contents($file);
        $this->assertStringStartsWith('#!/', $script); // a program cPanel can run without a shell
        $this->assertStringContainsString('artisan', $script);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out)); // valid PHP
        $this->assertStringContainsString('mailbox:pipe', $script);
        $this->assertSame('ok', CpanelMail::status()['status']);
    }

    public function test_nothing_changes_when_already_set_and_a_clear_message_without_access(): void
    {
        Http::fake(['*list_default_address*' => Http::response(['status' => 1, 'data' => [['domain' => 'example.com', 'defaultaddress' => '|' . sys_get_temp_dir() . '/meet-mail-pipe.php']]]),
            '*' => Http::response(['status' => 0, 'errors' => ['should not be called']])]);
        $this->assertStringContainsString('already', CpanelMail::ensure()['message']);

        config(['services.cpanel.token' => '']);
        $r = CpanelMail::ensure();
        if ($r['status'] === 'error') { // test machines have no uapi command
            $this->assertStringContainsString('CPANEL_API_TOKEN', $r['message']);
        }
        $this->artisan('mail:cpanel-setup')->assertSuccessful();
    }
}
