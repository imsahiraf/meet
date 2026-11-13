<?php

namespace Tests\Feature\Authentication;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Tests\TestCase;

class InsightsSsoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.insights.sso_secret' => 'shared-secret', 'services.insights.audience' => 'meet']);
    }

    private function token(array $over = [], string $secret = 'shared-secret'): string
    {
        $b64 = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $body = $b64(json_encode($over + ['iss' => 'insights', 'aud' => 'meet', 'sub' => 'asha@example.com', 'iat' => time(), 'exp' => time() + 300, 'jti' => Str::random(24)]));

        return $body . '.' . $b64(hash_hmac('sha256', $body, $secret, true));
    }

    public function test_valid_token_signs_in_the_existing_account_once(): void
    {
        $user = User::factory()->create(['email' => 'asha@example.com', 'status' => 'active', 'email_verified_at' => now()]);
        $t = $this->token();
        $this->get('/sso/insights?token=' . urlencode($t) . '&next=/meetings')->assertRedirect(url('/meetings'));
        $this->assertAuthenticatedAs($user);
        auth()->logout();
        $this->get('/sso/insights?token=' . urlencode($t))->assertRedirect(route('login')); // replay refused
        $this->assertGuest();
    }

    public function test_bad_tokens_and_unknown_accounts_are_refused(): void
    {
        User::factory()->create(['email' => 'asha@example.com', 'status' => 'active', 'email_verified_at' => now()]);
        foreach ([$this->token([], 'wrong'), $this->token(['exp' => time() - 1]), $this->token(['aud' => 'medicarematrix']), $this->token(['iss' => 'other']), 'garbage'] as $bad) {
            $this->get('/sso/insights?token=' . urlencode($bad))->assertRedirect(route('login'));
            $this->assertGuest();
        }
        $this->get('/sso/insights?token=' . urlencode($this->token(['sub' => 'nobody@example.com'])))->assertRedirect(route('login'));
        $this->assertSame(1, User::count(), 'no account is created');
    }

    public function test_open_redirect_and_suspended_accounts_are_blocked(): void
    {
        $user = User::factory()->create(['email' => 'asha@example.com', 'status' => 'active', 'email_verified_at' => now()]);
        $this->get('/sso/insights?token=' . urlencode($this->token()) . '&next=//evil.example')->assertRedirect(url('/'));
        auth()->logout();
        $user->update(['status' => 'suspended']);
        $this->get('/sso/insights?token=' . urlencode($this->token()))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_insights_users_get_a_meet_account_with_their_mail_size(): void
    {
        // the account is made by Insights' provision call (with the mail size of the package), then the entry signs in
        $claims = ['sub' => 'new.person@acme.test', 'name' => 'New Person', 'provision' => true, 'mail_mb' => 2048, 'plan' => 'Acme'];
        $this->postJson('/api/insights/provision', ['token' => $this->token($claims)])->assertOk();
        $this->get('/sso/insights?token=' . urlencode($this->token(['mail_mb' => 2048, 'plan' => 'Acme', 'sub' => 'new.person@acme.test'])))->assertRedirect(url('/'));
        $user = User::where('email', 'new.person@acme.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(['New Person', 'active'], [$user->full_name, $user->status]);
        $this->assertNotNull($user->email_verified_at);
        $s = \Modules\Mailbox\Models\MailSetting::for($user->id);
        $this->assertSame([2048, 'Insights: Acme'], [$s->quota_mb, $s->plan]);
    }

    public function test_an_existing_meet_account_can_be_linked_to_an_insights_login(): void
    {
        $own = User::factory()->create(['email' => 'asha.personal@gmail.test', 'status' => 'active', 'email_verified_at' => now()]);
        // signed in to the personal Meet account, comes back from Insights with link=1
        $this->actingAs($own)->get('/sso/insights?token=' . urlencode($this->token(['sub' => 'asha@acme.test', 'link' => true, 'org' => 'acme'])) . '&next=/settings/insights')
            ->assertRedirect('/settings/insights');
        $this->assertDatabaseHas('insights_links', ['user_id' => $own->id, 'insights_email' => 'asha@acme.test']);
        $this->actingAs($own)->get('/settings/insights')->assertOk()->assertSee('asha@acme.test');
        auth()->logout();
        // later "Open Meet" from Insights signs in to the linked personal account (no new account)
        $this->get('/sso/insights?token=' . urlencode($this->token(['sub' => 'asha@acme.test', 'provision' => true])));
        $this->assertAuthenticatedAs($own);
        $this->assertSame(0, User::where('email', 'asha@acme.test')->count());
    }

    public function test_insights_creates_and_links_the_account_and_entry_alone_never_does(): void
    {
        // entry with the provision claim still creates nothing
        $this->get('/sso/insights?token=' . urlencode($this->token(['sub' => 'new@example.com', 'provision' => true])))->assertRedirect(route('login'))
            ->assertSessionHasErrors('login');
        $this->assertGuest();
        $this->assertSame(0, User::where('email', 'new@example.com')->count());

        // the signed provision call makes the account, links it and can be repeated
        $this->postJson('/api/insights/provision', ['token' => $this->token(['sub' => 'new@example.com', 'name' => 'Nina New', 'provision' => true])])->assertOk()->assertJson(['ok' => true, 'created' => true]);
        $this->assertSame(1, User::where('email', 'new@example.com')->count());
        $this->assertDatabaseHas('insights_links', ['insights_email' => 'new@example.com']);
        $this->postJson('/api/insights/provision', ['token' => $this->token(['sub' => 'new@example.com', 'provision' => true])])->assertOk()->assertJson(['created' => false]);
        $this->assertSame(1, User::where('email', 'new@example.com')->count());

        // now the entry works
        $this->get('/sso/insights?token=' . urlencode($this->token(['sub' => 'new@example.com'])))->assertRedirect();
        $this->assertAuthenticated();

        // refused: wrong secret, no provision claim, replay
        $this->postJson('/api/insights/provision', ['token' => $this->token(['sub' => 'x@example.com', 'provision' => true], 'wrong')])->assertStatus(401);
        $this->postJson('/api/insights/provision', ['token' => $this->token(['sub' => 'x@example.com'])])->assertStatus(401);
        $this->assertSame(0, User::where('email', 'x@example.com')->count());
    }

    public function test_a_resellers_client_sees_the_resellers_brand(): void
    {
        $brand = ['name' => 'Acme Cloud', 'color' => '#0f766e', 'logo' => 'https://acme.test/logo.png', 'support_email' => 'help@acme.test'];
        $this->postJson('/api/insights/provision', ['token' => $this->token(['sub' => 'client@shop.test', 'name' => 'Client', 'provision' => true, 'org' => 'shop', 'brand' => $brand])])->assertOk();
        $user = User::where('email', 'client@shop.test')->firstOrFail();
        $user->forceFill(['status' => 'active', 'email_verified_at' => now()])->save();
        $this->get('/sso/insights?token=' . urlencode($this->token(['sub' => 'client@shop.test', 'brand' => $brand])) . '&next=/meetings');
        $this->assertAuthenticatedAs($user);
        $this->get('/meetings')->assertOk()->assertSee('<title>Meetings - Acme Cloud</title>', false)->assertSee('Acme Cloud')->assertSee('https://acme.test/logo.png', false)->assertSee('#0f766e', false);

        // the brand goes away when Insights stops sending it (the organization left the reseller)
        $this->get('/sso/insights?token=' . urlencode($this->token(['sub' => 'client@shop.test'])));
        $this->get('/meetings')->assertOk()->assertDontSee('Acme Cloud');
        // bad values are not used
        \App\Support\UserBrand::remember('client@shop.test', ['name' => 'X', 'logo' => 'javascript:alert(1)', 'color' => 'red;}']);
        $this->assertSame(['name' => 'X', 'logo' => null, 'color' => null, 'support_email' => null], \App\Support\UserBrand::for($user->id));
    }
}
