<?php

namespace Tests\Feature\Mailbox;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Models\MailMessage;
use Tests\TestCase;

class InsightsMailApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mailbox.domain' => 'example.com', 'mailbox.local_suffix' => '.user', 'services.insights.sso_secret' => 'shared-secret', 'mail.default' => 'array']);
        Storage::fake('local');
    }

    private function token(string $email, array $extra = [], string $secret = 'shared-secret'): string
    {
        $b64 = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $body = $b64(json_encode($extra + ['iss' => 'insights', 'aud' => 'meet', 'sub' => $email, 'scope' => 'mail', 'iat' => time(), 'exp' => time() + 300, 'jti' => Str::random(12)]));

        return $body . '.' . $b64(hash_hmac('sha256', $body, $secret, true));
    }

    public function test_insights_creates_reads_and_sends_the_meet_address(): void
    {
        $this->getJson('/api/insights/mail/account', ['Authorization' => 'Bearer ' . $this->token('asha@acme.test', [], 'wrong')])->assertStatus(401);
        $this->getJson('/api/insights/mail/account', ['Authorization' => 'Bearer ' . $this->token('nobody@acme.test')])->assertNotFound();

        $h = ['Authorization' => 'Bearer ' . $this->token('asha@acme.test', ['provision' => true, 'name' => 'Asha'])];
        $this->getJson('/api/insights/mail/account', $h)->assertOk()->assertJson(['email' => null, 'domain' => 'example.com', 'suffix' => '.user']);
        $this->postJson('/api/insights/mail/address', ['choice' => 'admin'], $h)->assertStatus(422);
        $this->postJson('/api/insights/mail/address', ['choice' => 'Asha.K'], $h)->assertCreated()->assertJson(['email' => 'asha.k.user@example.com']);
        $this->postJson('/api/insights/mail/address', ['choice' => 'second'], $h)->assertStatus(422);

        $u = User::where('email', 'asha@acme.test')->firstOrFail();
        $acc = MailAccount::where('user_id', $u->id)->where('kind', 'local')->firstOrFail();
        $raw = "From: Ravi <ravi@example.com>\r\nTo: asha.k.user@example.com\r\nSubject: Hello Asha\r\nMessage-ID: <m1@example.com>\r\nDate: " . date('r') . "\r\n\r\nHi there";
        \Modules\Mailbox\Services\Mailbox::ingest($acc, $raw);

        $r = $this->getJson('/api/insights/mail/messages?after=0', $h)->assertOk();
        $this->assertCount(1, $r->json('messages'));
        $this->assertStringContainsString('Hello Asha', base64_decode($r->json('messages.0.raw')));
        $this->assertSame(0, count($this->getJson('/api/insights/mail/messages?after=' . $r->json('last'), $h)->json('messages')));

        $s = $this->postJson('/api/insights/mail/send', ['to' => ['ravi@example.com'], 'subject' => 'From Insights', 'html' => '<p>Hi<script>x()</script></p>',
            'attachments' => [['filename' => 'a.txt', 'mime' => 'text/plain', 'content' => base64_encode('file')]]], $h)->assertCreated();
        $sent = MailMessage::findOrFail($s->json('id'));
        $this->assertSame(['sent', 'asha.k.user@example.com'], [$sent->folder, $sent->from_email]);
        $this->assertStringNotContainsString('script', (string) $sent->body_html);

        // another Insights login cannot reach this mailbox
        $other = ['Authorization' => 'Bearer ' . $this->token('mallory@acme.test', ['provision' => true])];
        $this->assertSame([], $this->getJson('/api/insights/mail/messages?after=0', $other)->json('messages'));
        $this->postJson('/api/insights/mail/send', ['to' => ['x@example.com']], $other)->assertNotFound();
    }

    /** Owner 2026-11-11: mail the person added in Meet (IMAP, Gmail) shows in Insights Mail Hub too; mailboxes that came from Insights do not go back. */
    public function test_accounts_added_in_meet_are_offered_to_insights(): void
    {
        $h = fn () => ['Authorization' => 'Bearer ' . $this->token('asha@acme.test', ['provision' => true, 'name' => 'Asha'])];
        $this->postJson('/api/insights/mail/address', ['choice' => 'asha'], $h())->assertCreated();
        $u = User::where('email', 'asha@acme.test')->firstOrFail();
        $gmail = MailAccount::create(['user_id' => $u->id, 'kind' => 'imap', 'email' => 'asha@gmail.test']);
        MailAccount::create(['user_id' => $u->id, 'kind' => 'insights', 'email' => 'asha@ashaco.test']); // came from Insights: never offered back

        $rows = $this->getJson('/api/insights/mail/accounts', $h())->assertOk()->json('accounts');
        $this->assertSame([['asha.user@example.com', 'meet'], ['asha@gmail.test', 'added']], array_map(fn ($r) => [$r['email'], $r['kind']], $rows));

        \Modules\Mailbox\Services\Mailbox::ingest($gmail, "From: Ravi <ravi@example.com>\r\nTo: asha@gmail.test\r\nSubject: Gmail one\r\nMessage-ID: <g1@example.com>\r\nDate: " . date('r') . "\r\n\r\nHi");
        $r = $this->getJson('/api/insights/mail/messages?after=0&account=' . $gmail->id, $h())->assertOk();
        $this->assertCount(1, $r->json('messages'));
        $this->assertStringContainsString('Gmail one', base64_decode($r->json('messages.0.raw')));
        $this->assertSame([], $this->getJson('/api/insights/mail/messages?after=0', $h())->json('messages'), 'without "account": the Meet address only (older Insights)');

        $insightsOne = MailAccount::where('kind', 'insights')->firstOrFail();
        $this->getJson('/api/insights/mail/messages?account=' . $insightsOne->id, $h())->assertNotFound();
        $other = ['Authorization' => 'Bearer ' . $this->token('mallory@acme.test', ['provision' => true])];
        $this->getJson('/api/insights/mail/messages?account=' . $gmail->id, $other)->assertNotFound();
        $this->postJson('/api/insights/mail/send', ['account' => $gmail->id, 'to' => ['x@example.com']], $other)->assertNotFound();
    }
}
