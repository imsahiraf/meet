<?php

namespace Tests\Feature\Mailbox;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Mailbox\ActiveSync\Wbxml as W;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Models\MailAppPassword;
use Modules\Mailbox\Services\Mailbox;
use Tests\TestCase;

/** Phone mail apps over Exchange ActiveSync in Meet (ported from Insights by Meet 2026-11-10): this test plays the phone. */
class ActiveSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $auth = '';

    private string $email = '';

    private function eas(string $cmd, ?array $body, string $device = 'dev1')
    {
        $q = http_build_query(['Cmd' => $cmd, 'User' => $this->email, 'DeviceId' => $device, 'DeviceType' => 'Android']);

        return $this->call('POST', '/Microsoft-Server-ActiveSync?' . $q, [], [], [], [
            'HTTP_AUTHORIZATION' => $this->auth, 'HTTP_MS_ASPROTOCOLVERSION' => '14.1', 'CONTENT_TYPE' => 'application/vnd.ms-sync.wbxml',
        ], $body ? W::encode($body) : '');
    }

    public function test_wbxml_round_trip(): void
    {
        $tree = W::el('AirSync:Sync', [W::el('AirSync:Collections', [W::el('AirSync:Collection', [W::text('AirSync:SyncKey', 5), W::text('Email:Subject', 'Héllo')])])]);
        $this->assertSame($tree, W::decode(W::encode($tree)));
    }

    public function test_a_phone_signs_in_and_syncs_the_inbox(): void
    {
        $u = User::factory()->create(['full_name' => 'Asha', 'status' => 'active', 'email_verified_at' => now()]);
        $this->email = 'asha' . uniqid() . '@example.com';
        $acc = MailAccount::create(['user_id' => $u->id, 'kind' => 'local', 'email' => $this->email, 'is_default' => true]);
        $m = Mailbox::ingest($acc, "From: Riya <riya@beta.test>\r\nTo: {$this->email}\r\nSubject: Quote for steel\r\nMessage-ID: <q1@beta.test>\r\nDate: " . date('r') . "\r\n\r\nPrice list\r\n");
        [, $plain] = MailAppPassword::make($u->id, 'My phone');
        $this->call('OPTIONS', '/Microsoft-Server-ActiveSync')->assertOk()->assertHeader('MS-ASProtocolVersions');
        $this->auth = 'Basic ' . base64_encode($this->email . ':wrong-password');
        $this->eas('FolderSync', W::el('FolderHierarchy:FolderSync', [W::text('FolderHierarchy:SyncKey', 0)]))->assertStatus(401);
        $this->auth = 'Basic ' . base64_encode($this->email . ':' . $plain);
        $fs = W::decode((string) $this->eas('FolderSync', W::el('FolderHierarchy:FolderSync', [W::text('FolderHierarchy:SyncKey', 0)]))->assertOk()->getContent());
        $this->assertSame('1', W::value($fs, 'FolderHierarchy:Status'));
        $sync = fn (int $key) => W::child(W::child(W::decode((string) $this->eas('Sync', W::el('AirSync:Sync', [W::el('AirSync:Collections', [W::el('AirSync:Collection', [
            W::text('AirSync:SyncKey', $key), W::text('AirSync:CollectionId', 'inbox')])])]))->assertOk()->getContent()), 'AirSync:Collections'), 'AirSync:Collection');
        $this->assertSame('1', W::value($sync(0), 'AirSync:SyncKey'));
        $add = W::child(W::child($sync(1), 'AirSync:Commands'), 'AirSync:Add');
        $this->assertSame((string) $m->id, W::value($add, 'AirSync:ServerId'));
        $this->assertSame('Quote for steel', W::value(W::child($add, 'AirSync:ApplicationData'), 'Email:Subject'));
    }

    public function test_app_passwords_are_made_and_removed_in_settings(): void
    {
        $u = User::factory()->create(['full_name' => 'Ravi', 'status' => 'active', 'email_verified_at' => now()]);
        $this->actingAs($u)->post('/mail/settings/app-passwords', ['name' => 'My Android'])->assertRedirect()->assertSessionHas('app_password');
        $ap = MailAppPassword::where('user_id', $u->id)->firstOrFail();
        $this->actingAs($u)->get('/mail/settings')->assertOk()->assertSee('My Android');
        $this->actingAs($u)->post("/mail/settings/app-passwords/{$ap->id}/remove")->assertRedirect();
        $this->assertSame(0, MailAppPassword::where('user_id', $u->id)->count());
    }
}
