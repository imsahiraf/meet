<?php

namespace Tests\Feature\Mailbox;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Authentication\Models\User;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Models\MailMessage;
use Modules\Mailbox\Services\Mailbox;
use Tests\TestCase;

class InsightsCompanyMailboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.insights.sso_secret' => 'shared-secret', 'services.insights.url' => 'https://insights.test', 'mail.default' => 'array']);
        Storage::fake('local');
    }

    /** What Insights answers; also checks every call is signed for the linked Insights login. */
    private function fakeInsights(array &$sent): void
    {
        $raw = base64_encode("From: Ravi <ravi@example.com>\r\nTo: sales@ashaco.test\r\nSubject: Order 12\r\nMessage-ID: <o12@example.com>\r\nDate: " . date('r') . "\r\n\r\nPlease ship.");
        Http::fake(function ($request) use (&$sent, $raw) {
            [$body, $sig] = explode('.', substr($request->header('Authorization')[0], 7));
            $this->assertSame(rtrim(strtr(base64_encode(hash_hmac('sha256', $body, 'shared-secret', true)), '+/', '-_'), '='), $sig);
            $claims = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
            $this->assertSame(['meet', 'insights', 'mail', 'boss@ashaco.test'], [$claims['iss'], $claims['aud'], $claims['scope'], $claims['sub']]);
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match ($path) {
                '/api/meet/mail/accounts' => Http::response(['accounts' => [['id' => 7, 'email' => 'sales@ashaco.test', 'name' => 'Sales']]]),
                '/api/meet/mail/messages' => Http::response(((int) ($request->data()['after'] ?? 0)) === 0
                    ? ['messages' => [['id' => 31, 'folder' => 'inbox', 'seen' => false, 'raw' => $raw]], 'last' => 31] : ['messages' => [], 'last' => 31]),
                '/api/meet/mail/send' => (function () use ($request, &$sent) {
                    $sent[] = $request->data();

                    return Http::response(['id' => 32, 'raw' => base64_encode("From: sales@ashaco.test\r\nTo: ravi@example.com\r\nSubject: Re: Order 12\r\nMessage-ID: <r12@ashaco.test>\r\n\r\nShipped.")], 201);
                })(),
                default => Http::response([], 404),
            };
        });
    }

    public function test_company_mailboxes_from_insights_are_added_synced_once_and_used_to_send(): void
    {
        $sent = [];
        $this->fakeInsights($sent);
        $u = User::factory()->create(['full_name' => 'Asha', 'email' => 'asha@personal.test', 'status' => 'active', 'email_verified_at' => now()]);
        DB::table('insights_links')->insert(['insights_email' => 'boss@ashaco.test', 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($u)->get('/mail/settings')->assertOk()->assertSee('Add my company mailboxes')->assertSee('boss@ashaco.test');
        $this->actingAs($u)->post('/mail/settings/insights-mailboxes')->assertSessionHas('status');
        $acc = MailAccount::where('user_id', $u->id)->where('kind', 'insights')->firstOrFail();
        $this->assertSame(['sales@ashaco.test', 7], [$acc->email, $acc->sync_state['insights_id']]);
        $this->assertSame(1, MailMessage::where('account_id', $acc->id)->where('folder', 'inbox')->count());

        // the next sync asks after the last message and does not add it twice
        $this->actingAs($u)->post("/mail/settings/accounts/{$acc->id}/sync")->assertSessionHas('status');
        $this->assertSame(1, MailMessage::where('account_id', $acc->id)->where('folder', 'inbox')->count());
        $this->actingAs($u)->post('/mail/settings/insights-mailboxes');
        $this->assertSame(1, MailAccount::where('user_id', $u->id)->where('kind', 'insights')->count());

        // writing from the company address goes out through Insights and the copy is in Sent
        Mailbox::send($acc, ['ravi@example.com'], [], [], 'Re: Order 12', '<p>Shipped.</p>');
        $this->assertSame([7, ['ravi@example.com']], [$sent[0]['account'], $sent[0]['to']]);
        $this->assertSame(1, MailMessage::where('account_id', $acc->id)->where('folder', 'sent')->count());
        $this->actingAs($u)->get('/mail/settings')->assertSee('Company mailbox (Meet Insights)');
    }

    public function test_hidden_without_insights_and_clear_message_without_mailboxes(): void
    {
        $u = User::factory()->create(['full_name' => 'Asha', 'status' => 'active', 'email_verified_at' => now()]);
        config(['services.insights.sso_secret' => '']);
        $this->actingAs($u)->get('/mail/settings')->assertOk()->assertDontSee('Add my company mailboxes');

        config(['services.insights.sso_secret' => 'shared-secret']);
        Http::fake(['*' => Http::response(['accounts' => []])]);
        $this->actingAs($u)->post('/mail/settings/insights-mailboxes')->assertSessionHasErrors('insights');
        $this->assertSame(0, MailAccount::where('user_id', $u->id)->count());
    }
}
