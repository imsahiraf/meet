<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The festive offer set in Meet Insights shows on Meet's pages; nothing breaks when Insights is not reachable. */
class OfferBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_offer_from_insights_is_shown(): void
    {
        Cache::forget('insights-offer-meet');
        Http::fake(['*/api/public/offer*' => Http::response(['offer' => ['title' => 'New Year: 2 months free', 'code' => 'NY26', 'colors' => ['#1e3a8a', '#a855f7'], 'emoji' => '🎆']])]);
        $this->assertStringContainsString('NY26', Blade::render('<x-offer-banner />'));
        Cache::forget('insights-offer-meet');
    }

    public function test_diwali_offer_shows_its_offline_line_and_festive_strip(): void
    {
        Cache::forget('insights-offer-meet');
        Http::fake(['*/api/public/offer*' => Http::response(['offer' => ['title' => 'Diwali 2026', 'theme' => 'diwali', 'colors' => ['#f59e0b', '#b91c1c'], 'emoji' => '🪔',
            'offline_text' => 'Offline shop software: Diwali price', 'offline_url' => 'https://github.com/imsahiraf/meet/issues']])]);
        $html = Blade::render('<x-offer-banner />');
        $this->assertStringContainsString('Offline shop software: Diwali price', $html);
        $this->assertStringContainsString('position:fixed', $html);
        Cache::forget('insights-offer-meet');
    }

    public function test_insights_down_shows_nothing(): void
    {
        Cache::forget('insights-offer-meet');
        Http::fake(['*' => Http::response('', 500)]);
        $this->assertSame('', trim(Blade::render('<x-offer-banner />')));
        Cache::forget('insights-offer-meet');
    }
}
