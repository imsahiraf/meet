<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_reflects_branding_settings(): void
    {
        \Modules\Admin\Models\BrandingSetting::current()->update(['app_name' => 'Custom Meet', 'theme_color' => '#123456']);

        $response = $this->getJson('/manifest.json');
        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('name', 'Custom Meet')
            ->assertJsonPath('theme_color', '#123456');

        $this->assertCount(3, $response->json('icons'));
    }

    public function test_manifest_locks_orientation_to_portrait(): void
    {
        // Installed (standalone) PWAs on Android otherwise let the
        // manifest's own orientation override the device's OS-level
        // rotation lock, rotating the app even while the phone itself
        // is locked to portrait.
        $response = $this->getJson('/manifest.json');
        $response->assertJsonPath('orientation', 'portrait');
    }

    // Static files under public/ aren't routed through the application
    // kernel PHPUnit's test client uses (no webserver is involved), so
    // /service-worker.js and /icons/*.png reachability is verified
    // against the real dev server instead - see tests/Browser/pwa.test.js.
}
