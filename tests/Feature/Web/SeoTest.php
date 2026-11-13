<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Only the sign-in / sign-up pages are for search engines; everything private is noindex. */
class SeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_have_seo_tags_and_private_ones_are_noindex(): void
    {
        $this->get('/login')->assertOk()->assertSee('<meta name="description"', false)->assertSee('og:title', false)->assertSee('application/ld+json', false)
            ->assertDontSee('noindex', false);
        $this->get('/forgot-password')->assertOk()->assertSee('noindex, nofollow', false);
        $this->get('/robots.txt')->assertOk()->assertSee('Allow: /login')->assertSee('Disallow: /')->assertSee('Sitemap: ' . url('/sitemap.xml'));
        $this->get('/sitemap.xml')->assertOk()->assertSee('<loc>' . url('/login') . '</loc>', false)->assertHeader('Content-Type', 'application/xml');
    }
}
