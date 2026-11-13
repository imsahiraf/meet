<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Admin\Models\BrandingSetting;

/**
 * SRS/16.md FR-257 - manifest values are configurable from the Admin
 * Panel (branding_settings) without requiring source code changes,
 * so this is served dynamically rather than a static /manifest.json
 * file.
 */
class PwaController extends Controller
{
    public function manifest(): JsonResponse
    {
        $branding = BrandingSetting::current();

        return response()->json([
            'name' => $branding->app_name,
            'short_name' => $branding->pwa_short_name,
            'description' => 'Chat, calls, and meetings - all in one place.',
            'start_url' => '/dashboard',
            'display' => $branding->pwa_display_mode,
            'orientation' => 'portrait',
            'background_color' => $branding->pwa_background_color,
            'theme_color' => $branding->theme_color,
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            // FR-266 App Shortcuts.
            'shortcuts' => [
                ['name' => 'New chat', 'url' => '/contacts'],
                ['name' => 'New meeting', 'url' => '/meetings'],
                ['name' => 'Calendar', 'url' => '/meetings'],
                ['name' => 'Profile', 'url' => '/profile'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }
}
