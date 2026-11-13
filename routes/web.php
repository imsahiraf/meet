<?php

use App\Http\Controllers\PwaController;
use Illuminate\Support\Facades\Route;

// Task 3.1 - the root route redirects based on authentication status
// instead of showing Laravel's default welcome page. Chat, not
// Dashboard, is the landing page - Dashboard is reachable from the
// sidebar/bottom nav like any other section now.
// a signed-in person goes to chat; a visitor sees the product website (owner 2026-11-13: sales pages and pricing like Teams)
Route::get('/', function () {
    return auth()->check() ? redirect()->route('chat.index') : redirect()->route('login');
})->name('home');

Route::get('manifest.json', [PwaController::class, 'manifest'])->name('pwa.manifest');

// SEO: search engines may read only the sign-in / sign-up pages; the app, meetings, chats, mail and the API stay out
Route::get('robots.txt', function () {
    $lines = ['User-agent: *', 'Allow: /$', 'Allow: /features', 'Allow: /pricing', 'Allow: /download', 'Allow: /login', 'Allow: /register', 'Disallow: /', '', 'Sitemap: ' . url('/sitemap.xml')];

    return response(implode("\n", $lines) . "\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
})->name('seo.robots');
Route::get('sitemap.xml', function () {
    $urls = array_map(fn ($u) => '<url><loc>' . e($u) . '</loc><changefreq>monthly</changefreq></url>', [url('/'), url('/features'), url('/pricing'), url('/download'), url('/login'), url('/register')]);

    return response('<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . implode('', $urls) . '</urlset>' . "\n", 200, ['Content-Type' => 'application/xml']);
})->name('seo.sitemap');
