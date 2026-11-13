<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Meet' }}</title>
    @php
        // SEO: only the sign-in and sign-up pages are for search engines; the rest of the guest pages (reset links, codes, invites) are not
        $indexable = request()->routeIs('login', 'register');
        $seoTitle = 'Meet - chat, calls, video meetings and business mail in one app';
        $seoText = 'Meet: WhatsApp-like chat, voice and video calls, video meetings with captions, recording and breakout rooms, and business mail - on the phone and the computer.';
    @endphp
    @if ($indexable)
    <meta name="description" content="{{ $seoText }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:type" content="website"><meta property="og:site_name" content="Meet">
    <meta property="og:title" content="{{ $seoTitle }}"><meta property="og:description" content="{{ $seoText }}">
    <meta property="og:url" content="{{ url()->current() }}"><meta property="og:image" content="{{ asset('icons/icon-512.png') }}">
    <meta name="twitter:card" content="summary">
    <script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => 'Meet',
        'applicationCategory' => 'CommunicationApplication', 'operatingSystem' => 'Web, Android', 'description' => $seoText, 'url' => url('/'),
        'publisher' => ['@type' => 'Organization', 'name' => 'Meet', 'url' => 'https://github.com/imsahiraf/meet']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @else
    <meta name="robots" content="noindex, nofollow">
    @endif
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('partials.pwa-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-gradient-to-br from-brand-50 via-white to-brand-100 antialiased">
    <x-offer-banner />
    <div class="flex min-h-full flex-col items-center justify-center px-4 py-12">
        <div class="mb-8 flex items-center gap-2">
            <img src="{{ asset('icons/icon-192.png') }}" alt="Meet" class="h-10 w-10 rounded-xl shadow-lg shadow-brand-600/30">
            <span class="text-xl font-semibold text-gray-900">Meet</span>
        </div>

        <div class="w-full max-w-md rounded-2xl border border-gray-100 bg-white/80 p-8 shadow-xl shadow-gray-200/50 backdrop-blur">
            @if (session('status'))
                <div class="mb-6 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700 border border-emerald-100">
                    {{ session('status') }}
                </div>
            @endif

            @if (isset($errors) && $errors->any())
                <div class="mb-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 border border-red-100">
                    <ul class="list-disc space-y-1 pl-4">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{ $slot }}
        </div>

        <p class="mt-8 text-xs text-gray-400">&copy; {{ date('Y') }} Meet &middot; v{{ config('app.version') }}. All rights reserved.</p>
    </div>
</body>
</html>
