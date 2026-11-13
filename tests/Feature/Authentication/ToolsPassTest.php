<?php

namespace Tests\Feature\Authentication;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Tests\TestCase;

class ToolsPassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.tools.pass_secret' => 'tools-secret', 'services.tools.hosts' => 'example.com,example.org']);
    }

    public function test_signed_in_user_goes_back_to_the_tool_with_a_signed_pass(): void
    {
        $user = User::factory()->create(['full_name' => 'Asha', 'email' => 'asha@example.com', 'status' => 'active', 'email_verified_at' => now()]);
        $res = $this->actingAs($user)->get('/tools/pass?return=' . urlencode('https://github.com/imsahiraf/meet'));
        $res->assertRedirect();
        $loc = $res->headers->get('Location');
        $this->assertStringStartsWith('https://github.com/imsahiraf/meet', $loc);
        [$body, $sig] = explode('.', substr($loc, strlen('https://github.com/imsahiraf/meet')));
        $this->assertSame(hash_hmac('sha256', $body, 'tools-secret'), $sig);
        $data = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
        $this->assertSame([$user->id, 'Asha', 'asha@example.com'], [$data['uid'], $data['name'], $data['email']]);
        $this->assertGreaterThan(time(), $data['exp']);
    }

    public function test_guests_log_in_first_and_foreign_return_addresses_are_refused(): void
    {
        $this->get('/tools/pass?return=' . urlencode('https://github.com/imsahiraf/meet'))->assertRedirect(route('login'));
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        foreach (['https://evil.example.com/', 'https://github.com/imsahiraf/meet', 'javascript:alert(1)', ''] as $bad) {
            $this->actingAs($user)->get('/tools/pass?return=' . urlencode($bad))->assertNotFound();
        }
    }
}
