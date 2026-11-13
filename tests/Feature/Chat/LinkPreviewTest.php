<?php

namespace Tests\Feature\Chat;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Authentication\Models\User;
use Tests\TestCase;

class LinkPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(): User
    {
        return User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    }

    public function test_a_public_url_returns_a_parsed_title_description_and_image(): void
    {
        Http::fake([
            'https://example.com/*' => Http::response(<<<'HTML'
                <html><head>
                    <title>Fallback Title</title>
                    <meta property="og:title" content="A Real Article">
                    <meta property="og:description" content="Something worth reading.">
                    <meta property="og:image" content="https://example.com/cover.jpg">
                </head></html>
                HTML, 200, ['Content-Type' => 'text/html; charset=utf-8']),
        ]);

        $user = $this->activeUser();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/link-preview?url='.urlencode('https://example.com/article'))
            ->assertStatus(200);

        $response->assertJsonPath('data.title', 'A Real Article');
        $response->assertJsonPath('data.description', 'Something worth reading.');
        $response->assertJsonPath('data.image', 'https://example.com/cover.jpg');
    }

    public function test_falls_back_to_the_title_tag_when_there_is_no_og_title(): void
    {
        Http::fake([
            '*' => Http::response('<html><head><title>Plain Title</title></head></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $user = $this->activeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/link-preview?url='.urlencode('https://example.com/plain'))
            ->assertJsonPath('data.title', 'Plain Title');
    }

    public function test_a_second_request_for_the_same_url_is_served_from_cache_not_fetched_again(): void
    {
        Cache::flush();
        Http::fake([
            '*' => Http::response('<html><head><title>Cached Title</title></head></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $user = $this->activeUser();
        $url = 'https://example.com/cache-me';

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/link-preview?url='.urlencode($url))->assertJsonPath('data.title', 'Cached Title');
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/link-preview?url='.urlencode($url))->assertJsonPath('data.title', 'Cached Title');

        Http::assertSentCount(1);
    }

    /**
     * The core SSRF defence: a URL whose host is (or resolves to) a
     * private/loopback/link-local address must never be fetched at
     * all - literal IPs so this is deterministic without depending on
     * real DNS. 169.254.169.254 is the real cloud-metadata SSRF target
     * this specifically has to block.
     */
    public function test_urls_pointing_at_private_loopback_or_link_local_addresses_are_never_fetched(): void
    {
        Http::fake();
        $user = $this->activeUser();

        foreach ([
            'http://127.0.0.1/admin',
            'http://localhost/admin',
            'http://10.0.0.5/internal',
            'http://192.168.1.1/router',
            'http://169.254.169.254/latest/meta-data/',
            'http://[::1]/admin',
        ] as $dangerousUrl) {
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/link-preview?url='.urlencode($dangerousUrl))
                ->assertStatus(200)
                ->assertJsonPath('data', null);
        }

        Http::assertNothingSent();
    }

    public function test_non_http_schemes_are_rejected_without_fetching_anything(): void
    {
        Http::fake();
        $user = $this->activeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/link-preview?url='.urlencode('file:///etc/passwd'))
            ->assertJsonPath('data', null);

        Http::assertNothingSent();
    }

    public function test_a_non_html_response_is_not_treated_as_a_preview(): void
    {
        Http::fake([
            '*' => Http::response('{"not":"html"}', 200, ['Content-Type' => 'application/json']),
        ]);

        $user = $this->activeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/link-preview?url='.urlencode('https://example.com/api/data'))
            ->assertJsonPath('data', null);
    }

}
