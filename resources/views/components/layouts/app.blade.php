<!DOCTYPE html>
@php [$accent, $accentHex] = \Modules\User\Support\Look::resolve(auth()->user()); @endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" data-accent="{{ $accent }}">
<head>
    <meta charset="utf-8">
    @if ($accentHex)<style>{!! \Modules\User\Support\Look::css($accentHex) !!}</style>@endif
    {{-- signed-in pages are private: never in search engines --}}
    <meta name="robots" content="noindex, nofollow">
    {{-- maximum-scale=1/user-scalable=no kills native pinch/double-tap
         zoom app-wide (v1.2.5: reported as page "zoom" jumping around on
         focus/typing on some Android/iOS devices). The image viewer
         implements its own JS-driven pinch/double-tap zoom independent
         of this, since native viewport zoom can't be scoped to one
         element. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    @php $brand = \App\Support\UserBrand::for(auth()->id()); @endphp
    <title>{{ $brand ? preg_replace('/\bMeet\b/', $brand['name'], $title ?? 'Meet') : ($title ?? 'Meet') }}</title>
    @if ($brand && $brand['color'])<style>:root{--brand-color:{{ $brand['color'] }}}.bg-brand-600{background-color:{{ $brand['color'] }}!important}.text-brand-600,.text-brand-700{color:{{ $brand['color'] }}!important}</style>@endif
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="current-user-uuid" content="{{ auth()->user()->uuid }}">
    {{-- A call is N-party now: the client keys its peer connections and
         video tiles by the OTHER participant's user id, so it needs to
         know which of the ids in a participant list is its own. (The
         uuid above is used for a different purpose - matching contact
         requests - and the calls API speaks in numeric ids.) --}}
    <meta name="current-user-id" content="{{ auth()->id() }}">
    <meta name="current-user-name" content="{{ auth()->user()->full_name }}">
    <meta name="notify-preferences" content="{{ json_encode([
        'messages' => auth()->user()->preferences?->notify_messages ?? true,
        'calls' => auth()->user()->preferences?->notify_calls ?? true,
        'meetings' => auth()->user()->preferences?->notify_meetings ?? true,
        'contact_requests' => auth()->user()->preferences?->notify_contact_requests ?? true,
        'sound' => auth()->user()->preferences?->notification_sound ?? true,
    ]) }}">
    @if (config('firebase.api_key') && config('firebase.vapid_key'))
        <meta name="firebase-api-key" content="{{ config('firebase.api_key') }}">
        <meta name="firebase-auth-domain" content="{{ config('firebase.auth_domain') }}">
        <meta name="firebase-project-id" content="{{ config('firebase.project_id') }}">
        <meta name="firebase-storage-bucket" content="{{ config('firebase.storage_bucket') }}">
        <meta name="firebase-messaging-sender-id" content="{{ config('firebase.messaging_sender_id') }}">
        <meta name="firebase-app-id" content="{{ config('firebase.app_id') }}">
        <meta name="firebase-vapid-key" content="{{ config('firebase.vapid_key') }}">
    @endif
    @include('partials.pwa-head')
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/e2e-crypto.js', 'resources/js/offline.js', 'resources/js/push-notifications.js'])
</head>
<body class="h-full bg-gray-50 antialiased">
    <x-offer-banner />
    @php
        // An open conversation is meant to look like WhatsApp/Instagram
        // on a phone - the *entire* screen is the conversation itself
        // (its own header, messages, composer), with no app-level
        // chrome (this layout's own header/bottom nav) visible at all;
        // back navigation is the conversation's own back arrow, not a
        // persistent nav bar. Desktop is unaffected - the WhatsApp-Web-
        // style split view (sidebar + header + thread) already looks
        // right there, this is a mobile-only concern. Opt-in via a
        // prop (default false) so only the pages that actually want
        // this - chat/show.blade.php - set it, not the whole app.
        $fullBleedMobile = $fullBleedMobile ?? false;
    @endphp
    {{-- A deliberate reversal of this app's original narrow offline
         scope (FR-259 excluded chat sync) - see Documentation's
         consolidated notes. Calls/meetings/uploads are still never
         offline-capable; only chat/contacts data caching and a queued-
         send outbox are. Normal document flow, same reasoning as the
         E2E banner above. --}}
    <div id="offline-banner" class="hidden items-center justify-between gap-2 bg-amber-500 px-4 py-1.5 text-xs font-medium text-white">
        <span>You're offline - showing cached data. <span id="offline-queue-count"></span></span>
    </div>

    @include('partials.meet-dialog')

    @php
        $current = request()->route()?->getName();
        $navItems = [
            ['route' => 'chat.index', 'label' => 'Chat', 'icon' => 'chat', 'activePrefixes' => ['chat', 'groups'], 'badgeKey' => 'chat'],
            ['route' => 'contacts.index', 'label' => 'Contacts', 'icon' => 'contacts', 'badgeKey' => 'contacts'],
            ['route' => 'calls.history', 'label' => 'Calls', 'icon' => 'calls'],
            ['route' => 'mail.index', 'label' => 'Mail', 'icon' => 'mail', 'activePrefixes' => ['mail']],
            // Both of these route names start with "meetings.", so the
            // generic same-first-segment prefix fallback would light
            // each of them up on the OTHER's pages too (Meetings via
            // its own "meetings." prefix catching meetings.calendar;
            // Calendar via that identical fallback catching
            // meetings.index/meetings.history right back). Meetings
            // gets an explicit exclusion for the one route it should
            // NOT claim; Calendar gets no prefix fallback at all - it
            // has exactly one route, so an exact match is all it needs.
            ['route' => 'meetings.index', 'label' => 'Meetings', 'icon' => 'meetings', 'excludeRoutes' => ['meetings.calendar']],
            ['route' => 'meetings.calendar', 'label' => 'Calendar', 'icon' => 'calendar', 'activePrefixes' => []],
        ];
        $isActive = function ($routeNameOrItem) use ($current) {
            $routeName = is_array($routeNameOrItem) ? $routeNameOrItem['route'] : $routeNameOrItem;
            $prefixes = is_array($routeNameOrItem)
                ? ($routeNameOrItem['activePrefixes'] ?? [explode('.', $routeName)[0]])
                : [explode('.', $routeName)[0]];
            $excludeRoutes = is_array($routeNameOrItem) ? ($routeNameOrItem['excludeRoutes'] ?? []) : [];

            if (in_array($current, $excludeRoutes, true)) {
                return false;
            }

            if ($current === $routeName) {
                return true;
            }

            foreach ($prefixes as $prefix) {
                if (str_starts_with($current ?? '', $prefix.'.')) {
                    return true;
                }
            }

            return false;
        };
    @endphp

    <div class="flex h-dvh flex-col lg:flex-row">
        {{-- Desktop sidebar: icon-only slim rail (WhatsApp-Web-style), hidden below lg. --}}
        <nav class="hidden w-[72px] shrink-0 flex-col items-center gap-1 border-r border-gray-200 bg-white py-4 lg:flex">
            <a href="{{ route('chat.index') }}" class="mb-3">
                <img src="{{ $brand['logo'] ?? asset('icons/icon-192.png') }}" alt="{{ $brand['name'] ?? 'Meet' }}" class="h-9 w-9 rounded-lg shadow shadow-brand-600/30" style="object-fit:contain">
            </a>

            @foreach ($navItems as $item)
                <a href="{{ route($item['route']) }}" title="{{ $item['label'] }}"
                   class="relative flex h-11 w-11 items-center justify-center rounded-xl transition
                          {{ $isActive($item) ? 'bg-brand-50 text-brand-700' : 'text-gray-500 hover:bg-gray-100 hover:text-gray-800' }}">
                    <x-dynamic-component :component="'icons.'.$item['icon']" class="h-6 w-6" />
                    @if (isset($item['badgeKey']))
                        <span data-nav-badge="{{ $item['badgeKey'] }}" class="absolute right-1.5 top-1.5 hidden h-2 w-2 rounded-full bg-red-500"></span>
                    @endif
                </a>
            @endforeach

            <div class="flex-1"></div>

            <a href="{{ route('dashboard') }}" title="Dashboard"
               class="mb-1 flex h-11 w-11 items-center justify-center rounded-xl transition
                      {{ $isActive('dashboard') ? 'bg-brand-50 text-brand-700' : 'text-gray-500 hover:bg-gray-100 hover:text-gray-800' }}">
                <x-icons.dashboard class="h-6 w-6" />
            </a>
            @if (auth()->user()->admin)
                <a href="{{ route('admin.dashboard') }}" title="Admin"
                   class="flex h-11 w-11 items-center justify-center rounded-xl transition
                          {{ str_starts_with($current ?? '', 'admin.') ? 'bg-brand-50 text-brand-700' : 'text-gray-500 hover:bg-gray-100 hover:text-gray-800' }}">
                    <x-icons.admin class="h-6 w-6" />
                </a>
            @endif
        </nav>

        <div class="flex min-h-0 flex-1 flex-col">
            <header class="{{ $fullBleedMobile ? 'hidden' : '' }} border-b border-gray-200 bg-white/90 backdrop-blur sticky top-0 z-20 lg:static lg:block">
                <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
                    <a href="{{ route('chat.index') }}" class="flex items-center gap-2 lg:hidden">
                        <img src="{{ $brand['logo'] ?? asset('icons/icon-192.png') }}" alt="{{ $brand['name'] ?? 'Meet' }}" class="h-8 w-8 rounded-lg shadow shadow-brand-600/30" style="object-fit:contain">
                        <span class="text-lg font-semibold text-gray-900">{{ $brand['name'] ?? 'Meet' }}</span>
                    </a>
                    <div class="hidden lg:block"></div>

                    <div class="flex items-center gap-3">
                        <button type="button" id="pwa-install-btn" class="hidden rounded-lg border border-brand-200 px-3 py-1.5 text-sm font-medium text-brand-700 hover:bg-brand-50">
                            Install app
                        </button>
                        {{-- On mobile (bottom bar only has room for the 5 main sections),
                             Dashboard/Admin are reached from the profile page this leads to,
                             not from a persistent header link. --}}
                        {{-- Logout itself lives in Settings > Account & Security now,
                             not as a persistent header button - this avatar link is
                             the entry point to get there (and to everything else on
                             that page). --}}
                        <a href="{{ route('profile.edit') }}"
                           class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm font-medium text-gray-600 hover:bg-gray-100">
                            @if (auth()->user()->profile_photo)
                                <img src="{{ auth()->user()->profile_photo }}" alt="" class="h-7 w-7 rounded-full" style="object-fit:cover">
                            @else
                            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">
                                {{ strtoupper(substr(auth()->user()->full_name ?? '?', 0, 1)) }}
                            </span>
                            @endif
                            <span class="hidden sm:inline">{{ auth()->user()->full_name }}</span>
                        </a>
                    </div>
                </div>
            </header>

            {{-- A full-bleed page's single child (#chat-app-container)
                 already owns 100% of this element's height and scrolls
                 itself internally (#messages) - if <main> were ALSO a
                 scroll container (overflow-y-auto, as every other page
                 needs), any touch-scroll gesture that maxes out the
                 inner list can rubber-band/scroll-chain into this outer
                 one on mobile, sliding the whole header/messages/
                 composer stack up within <main> and leaving a visible
                 gap below the composer - exactly the reported "footer
                 comes up and leaves a gap" bug. overflow-hidden here
                 (mobile only; desktop's card layout has no such
                 mobile-only quirk to guard against) removes that second
                 scrollable layer entirely. --}}
            <main id="app-main" class="mx-auto w-full max-w-6xl flex-1 min-h-0 overscroll-y-contain {{ $fullBleedMobile ? 'overflow-hidden lg:overflow-y-auto px-0 py-0 pb-0' : 'overflow-y-auto px-4 py-6 pb-32' }} lg:px-4 lg:py-6 lg:pb-6">
                @if (session('status'))
                    <div class="mb-6 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                        {{ session('status') }}
                    </div>
                @endif

                @if (isset($errors) && $errors->any() && ! request()->routeIs('mail.*')) {{-- mail pages show their own message next to the form --}}
                    <div class="mb-6 rounded-lg border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-700">
                        <ul class="list-disc space-y-1 pl-4">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{ $slot }}
            </main>

            <footer class="hidden border-t border-gray-100 px-4 py-3 text-center text-xs text-gray-400 lg:block">
                {{ $brand ? $brand['name'] : 'Meet' }} v{{ config('app.version') }}
            </footer>
        </div>
    </div>

    {{-- Mobile bottom bar: the 5 main sections, hidden at lg and up.
         id is a hook for an active meeting room (room-script.blade.php)
         to hide this entirely while in an active call - both this bar
         and the meeting's own floating control bar are fixed to the
         same bottom-0 position, and this bar's z-30 sits above the
         room's z-20 bar, so without hiding it, it silently covers every
         in-call control (Mute/Camera/Leave/etc become untappable). --}}
    <nav id="app-bottom-nav" class="fixed inset-x-0 bottom-0 z-30 {{ $fullBleedMobile ? 'hidden' : 'flex' }} items-stretch justify-around border-t border-gray-200 bg-white/95 backdrop-blur lg:hidden">
        @foreach ($navItems as $item)
            <a href="{{ route($item['route']) }}"
               class="relative flex flex-1 flex-col items-center gap-0.5 py-2 text-[11px] font-medium
                      {{ $isActive($item) ? 'text-brand-700' : 'text-gray-500' }}">
                <span class="relative inline-flex">
                    <x-dynamic-component :component="'icons.'.$item['icon']" class="h-6 w-6" />
                    @if (isset($item['badgeKey']))
                        <span data-nav-badge="{{ $item['badgeKey'] }}" class="absolute -right-0.5 -top-0.5 hidden h-2 w-2 rounded-full bg-red-500"></span>
                    @endif
                </span>
                {{-- whitespace-nowrap - without it, "Meetings"/"Calendar"
                     (the two longest labels) could wrap to a second line
                     inside their narrow 1/5-width column on a small
                     phone, making the whole bar taller/uneven. --}}
                <span class="whitespace-nowrap">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>

    <div id="ios-install-instructions" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 p-4">
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl">
            <h2 class="text-base font-semibold text-gray-900">Install Meet on your iPhone/iPad</h2>
            <ol class="mt-4 space-y-3 text-sm text-gray-600">
                <li class="flex items-start gap-2">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">1</span>
                    Tap the <strong>Share</strong> icon (square with an arrow pointing up) in Safari's toolbar.
                </li>
                <li class="flex items-start gap-2">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">2</span>
                    Scroll down and tap <strong>Add to Home Screen</strong>.
                </li>
                <li class="flex items-start gap-2">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">3</span>
                    Tap <strong>Add</strong> in the top-right corner.
                </li>
            </ol>
            <p class="mt-4 text-xs text-gray-400">This must be done from Safari - other browsers on iOS can't add apps to your home screen.</p>
            <button type="button" id="ios-install-close" class="mt-5 w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
                Got it
            </button>
        </div>
    </div>

    {{-- .call-panel (app.css) rather than a bare `fixed inset-0`: a
         fixed element's inset-0 resolves against the LARGE viewport, so
         on a real phone the bottom control row sat underneath mobile
         Safari/Chrome's own toolbar and the home-indicator area - the
         reported "call UI doesn't work on mobile/PWA". .call-panel is
         100dvh (the *dynamic* viewport, which excludes a visible browser
         toolbar) plus env(safe-area-inset-*) padding. --}}
    <div id="call-overlay" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60">
        {{-- Ringing states (before the other party answers/you answer)
             share the same full-screen, dark, WhatsApp/FaceTime-style
             treatment as the connected call-active screen below. These
             stay the 1:1 call-STARTING UI - a call only grows past two
             people via "Add member" once it's already connected. --}}
        <div id="call-incoming" class="call-panel hidden flex-col items-center justify-between">
            <p class="call-e2e"><x-icons.lock class="h-3.5 w-3.5" /> End-to-end encrypted</p>
            <div class="flex flex-1 flex-col items-center justify-start gap-5 px-6 pt-10 text-center">
                <div>
                    <p id="call-incoming-name" class="text-2xl font-semibold text-white"></p>
                    <p id="call-incoming-type" class="mt-1 text-sm text-white/80"></p>
                </div>
                <span class="call-ring-avatar"><span id="call-incoming-avatar" class="call-glass-avatar h-32 w-32 text-5xl"></span></span>
            </div>
            <div class="grid w-full max-w-sm grid-cols-3 gap-x-6 px-6 pb-6">
                <button type="button" id="call-reject-btn" class="call-ring-btn">
                    <span class="flex h-16 w-16 items-center justify-center rounded-full bg-red-600"><x-icons.phone-end class="h-6 w-6 text-white" /></span>
                    <span class="text-xs font-medium text-white">Decline</span>
                </button>
                {{-- decline with a short message to the caller (sent in your chat with them, encrypted like any message) --}}
                <button type="button" id="call-quick-msg-btn" class="call-ring-btn">
                    <span class="call-glass-avatar h-16 w-16"><x-icons.chat class="h-6 w-6 text-white" /></span>
                    <span class="text-xs font-medium text-white">Message</span>
                </button>
                <button type="button" id="call-accept-btn" class="call-ring-btn call-accept-bounce">
                    <span class="flex h-16 w-16 items-center justify-center rounded-full bg-emerald-600"><x-icons.phone-accept class="h-6 w-6 text-white" /></span>
                    <span class="text-xs font-medium text-white">Accept</span>
                </button>
            </div>
        </div>

        <div id="call-quick-sheet" class="fixed inset-x-0 bottom-0 z-[60] hidden flex-col gap-1 rounded-t-3xl bg-white p-4 pb-[calc(env(safe-area-inset-bottom)+1rem)] shadow-2xl" role="dialog" aria-label="Decline with a message">
            <p class="px-2 pb-1 text-sm font-semibold text-gray-900">Decline and send a message</p>
            <button type="button" class="call-quick-choice rounded-xl px-3 py-3 text-left text-sm text-gray-800 hover:bg-gray-100">Can't talk now. I'll call you back.</button>
            <button type="button" class="call-quick-choice rounded-xl px-3 py-3 text-left text-sm text-gray-800 hover:bg-gray-100">I'll call you back in 10 minutes.</button>
            <button type="button" class="call-quick-choice rounded-xl px-3 py-3 text-left text-sm text-gray-800 hover:bg-gray-100">I'm in a meeting. Please send a message.</button>
            <button type="button" class="call-quick-choice rounded-xl px-3 py-3 text-left text-sm text-gray-800 hover:bg-gray-100">On my way.</button>
            <form id="call-quick-form" class="mt-1 flex gap-2 px-1">
                <input id="call-quick-input" type="text" maxlength="500" placeholder="Write your own..." class="min-w-0 flex-1 rounded-xl border border-gray-300 px-3 py-2 text-sm" aria-label="Your own message">
                <button type="submit" class="rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white">Send</button>
            </form>
            <button type="button" id="call-quick-cancel" class="mt-1 rounded-xl px-3 py-2 text-sm font-medium text-gray-500">Back to the call</button>
        </div>

        {{-- After a connected call: who, how long, call again or message (closes by itself after a few seconds) --}}
        <div id="call-polls-sheet" class="fixed inset-x-0 bottom-0 z-[60] mx-auto hidden max-h-[80vh] w-full max-w-lg flex-col rounded-t-3xl bg-white pb-[env(safe-area-inset-bottom)] text-gray-900 shadow-2xl" role="dialog" aria-label="Polls and questions">
            <div class="flex items-center gap-2 border-b border-gray-100 px-4 pt-4 pb-2">
                <button type="button" data-polls-tab="polls" class="call-polls-tab rounded-full px-3 py-1.5 text-sm font-semibold">Polls</button>
                <button type="button" data-polls-tab="questions" class="call-polls-tab rounded-full px-3 py-1.5 text-sm font-semibold">Questions</button>
                <button type="button" id="call-polls-close" class="ml-auto rounded-full p-2 text-gray-500" aria-label="Close">&times;</button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
                <div id="call-polls-pane">
                    <div id="call-polls-list" class="flex flex-col gap-3"></div>
                    <form id="call-poll-form" class="mt-3 flex flex-col gap-2 rounded-2xl bg-gray-50 p-3">
                        <input id="call-poll-q" type="text" maxlength="200" required placeholder="Ask everyone something..." class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm" aria-label="Poll question">
                        <div id="call-poll-options" class="flex flex-col gap-2"></div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" id="call-poll-add-option" class="rounded-full border border-gray-300 px-3 py-1.5 text-xs font-medium">Add choice</button>
                            <button type="submit" class="ml-auto rounded-full bg-indigo-600 px-4 py-1.5 text-xs font-semibold text-white">Start poll</button>
                        </div>
                    </form>
                </div>
                <div id="call-questions-pane" class="hidden">
                    <div id="call-questions-list" class="flex flex-col gap-2"></div>
                    <form id="call-question-form" class="mt-3 flex gap-2">
                        <input id="call-question-input" type="text" maxlength="300" required placeholder="Ask a question..." class="min-w-0 flex-1 rounded-xl border border-gray-300 px-3 py-2 text-sm" aria-label="Your question">
                        <button type="submit" class="rounded-xl bg-indigo-600 px-3 py-2 text-sm font-semibold text-white">Ask</button>
                    </form>
                </div>
            </div>
        </div>

        <div id="call-ended" class="call-panel hidden flex-col items-center justify-between">
            <p class="call-e2e"><x-icons.lock class="h-3.5 w-3.5" /> End-to-end encrypted</p>
            <div class="flex flex-1 flex-col items-center justify-start gap-5 px-6 pt-10 text-center">
                <div>
                    <p id="call-ended-name" class="text-2xl font-semibold text-white"></p>
                    <p id="call-ended-info" class="mt-1 text-sm text-white/80">Call ended</p>
                </div>
                <span id="call-ended-avatar" class="call-glass-avatar h-32 w-32 text-5xl"></span>
            </div>
            <div class="grid w-full max-w-sm grid-cols-3 gap-x-6 px-6 pb-6">
                <button type="button" id="call-ended-close" class="call-ring-btn">
                    <span class="call-glass-avatar h-16 w-16"><x-icons.x-circle class="h-6 w-6 text-white" /></span>
                    <span class="text-xs font-medium text-white">Close</span>
                </button>
                <button type="button" id="call-ended-msg" class="call-ring-btn">
                    <span class="call-glass-avatar h-16 w-16"><x-icons.chat class="h-6 w-6 text-white" /></span>
                    <span class="text-xs font-medium text-white">Message</span>
                </button>
                <button type="button" id="call-ended-again" class="call-ring-btn">
                    <span class="flex h-16 w-16 items-center justify-center rounded-full bg-emerald-600"><x-icons.phone-accept class="h-6 w-6 text-white" /></span>
                    <span class="text-xs font-medium text-white">Call again</span>
                </button>
            </div>
        </div>

        <div id="call-outgoing" class="call-panel hidden flex-col items-center justify-between">
            <p class="call-e2e"><x-icons.lock class="h-3.5 w-3.5" /> End-to-end encrypted</p>
            <div class="flex flex-1 flex-col items-center justify-start gap-5 px-6 pt-10 text-center">
                <div>
                    <p id="call-outgoing-name" class="text-2xl font-semibold text-white"></p>
                    <p class="mt-1 text-sm text-white/80">Calling&hellip;</p>
                </div>
                <span class="call-ring-avatar"><span id="call-outgoing-avatar" class="call-glass-avatar h-32 w-32 text-5xl"></span></span>
            </div>
            <div class="px-6 pb-6">
                <button type="button" id="call-cancel-btn" class="call-ring-btn">
                    <span class="flex h-16 w-16 items-center justify-center rounded-full bg-red-600"><x-icons.phone-end class="h-6 w-6 text-white" /></span>
                    <span class="text-xs font-medium text-white">Cancel</span>
                </button>
            </div>
        </div>

        {{-- The connected call. This used to be one big video element
             plus one big avatar - the whole screen belonged to "the
             other party", because a call could only ever have one. It's
             a real participant grid now (#call-grid, one tile per
             person, growing and shrinking as people join and leave via
             "Add member" or hanging up), built the same way the meeting
             room's own multi-tile grid is. --}}
        <div id="call-active" class="call-panel hidden flex-col">
            {{-- Top bar: connection status left, name/participant count
                 + timer centered. --}}
            <div class="absolute inset-x-0 top-0 z-10 flex items-center gap-2 bg-gradient-to-b from-black/60 to-transparent p-4">
                <span id="call-connection-status" class="hidden items-center gap-1.5 rounded-full bg-black/40 px-2.5 py-1 text-xs font-medium text-white">
                    <span id="call-connection-dot" class="h-1.5 w-1.5 rounded-full bg-amber-400"></span>
                    <span id="call-connection-label">Connecting…</span>
                </span>
                <div class="flex-1 text-center">
                    <p id="call-active-name" class="text-sm font-semibold text-white"></p>
                    <p id="call-active-timer" class="text-xs text-white/80">00:00</p>
                </div>
                <span class="flex w-16 justify-end gap-1">
                    <button type="button" id="call-pip-btn" class="hidden rounded-full bg-white/20 p-2 text-white" aria-label="Picture in picture" title="Picture in picture">
                        <x-icons.screen-share class="h-4 w-4" />
                    </button>
                    <button type="button" id="call-minimise-btn" class="rounded-full bg-white/20 p-2 text-white" aria-label="Minimise call - keep using the app" title="Minimise">
                        <x-icons.chevron-left class="h-4 w-4 -rotate-90" />
                    </button>
                </span>
            </div>

            {{-- Tiles are created/destroyed by JS (tileFor()/removeTile()
                 below) - one per participant including yourself, each
                 carrying that person's own media element, avatar
                 fallback, name and muted badge. The tiles-N layout
                 classes are the same adaptive ones the meeting room
                 uses (see .tiles-3/.tiles-many in app.css). --}}
            <div id="call-grid" class="call-grid tiles-1 grid-cols-1"></div>

            <span id="call-screen-sharing-badge" class="absolute left-3 top-16 z-10 hidden items-center rounded-lg bg-black/70 px-2 py-1 text-xs font-medium text-white">Sharing your screen</span>
            <span id="call-remote-sharing-badge" class="absolute left-3 top-16 z-10 hidden items-center rounded-lg bg-black/70 px-2 py-1 text-xs font-medium text-white"></span>
            <div id="call-reaction-float-layer" class="pointer-events-none absolute inset-0 overflow-hidden"></div>

            <div id="call-reaction-menu" class="absolute bottom-40 left-1/2 z-20 hidden -translate-x-1/2 items-center justify-center gap-3 rounded-full bg-white/10 px-3 py-2"></div>
            {{-- "More" overflow menu - reactions + add member, so the
                 main control grid stays a clean 2-row-of-3 icon grid
                 matching the reference instead of a flat row of 5
                 buttons. --}}
            <div id="call-more-menu" class="absolute bottom-28 left-1/2 z-30 hidden max-h-[calc(100%-9rem)] w-60 max-w-[calc(100vw-2rem)] -translate-x-1/2 flex-col overflow-y-auto rounded-xl bg-white py-1 shadow-2xl">
                <button type="button" id="call-more-react-btn" class="px-4 py-3 text-left text-sm font-medium text-gray-700">React</button>
                <button type="button" id="call-more-add-member-btn" class="px-4 py-3 text-left text-sm font-medium text-gray-700">Add member</button>
                {{-- Video calls only - moved in here from the main control
                     row so a video call's primary row looks exactly like a
                     voice call's (Speaker/Mute/More), same as Flip camera
                     below it, rather than showing an extra button/label
                     the voice screen never has. --}}
                <button type="button" id="call-share-btn" class="px-4 py-3 text-left text-sm font-medium text-gray-700">
                    <span class="call-control-label">Share</span> screen
                </button>
                {{-- Only shown at all once a second camera is actually
                     confirmed present (see updateFlipCameraAvailability())
                     - hidden rather than shown-but-broken on a
                     single-camera device. --}}
                <button type="button" id="call-more-flip-camera-btn" class="hidden px-4 py-3 text-left text-sm font-medium text-gray-700">Flip camera</button>
                {{-- Call features of the well-known call apps (owner 2026-11-08), see call-extras.blade.php --}}
                <button type="button" id="call-more-video-btn" class="hidden px-4 py-3 text-left text-sm font-medium text-gray-700">Turn on video</button>
                <button type="button" id="call-more-blur-btn" class="call-more-toggle hidden px-4 py-3 text-left text-sm font-medium text-gray-700">Background blur</button>
                <button type="button" id="call-more-noise-btn" class="call-more-toggle px-4 py-3 text-left text-sm font-medium text-gray-700">Noise reduction</button>
                <button type="button" id="call-more-captions-btn" class="call-more-toggle px-4 py-3 text-left text-sm font-medium text-gray-700">Captions</button>
                <button type="button" id="call-more-polls-btn" class="px-4 py-3 text-left text-sm font-medium text-gray-700">Polls and questions <span id="call-polls-new" class="ml-1 hidden rounded-full bg-indigo-600 px-1.5 text-xs text-white"></span></button>
                <button type="button" id="call-more-layout-btn" class="call-more-toggle hidden px-4 py-3 text-left text-sm font-medium text-gray-700">Speaker view</button>
                <button type="button" id="call-more-lowdata-btn" class="call-more-toggle px-4 py-3 text-left text-sm font-medium text-gray-700">Low data mode</button>
            </div>

            <div id="call-waiting" class="absolute inset-x-3 top-16 z-30 mx-auto hidden max-w-md flex-wrap items-center gap-3 rounded-2xl bg-white/95 p-3 text-gray-900 shadow-2xl" role="alertdialog" aria-label="Another call">
                <span id="call-waiting-avatar" class="call-glass-avatar h-10 w-10 shrink-0 bg-indigo-600 text-sm text-white"></span>
                <span class="min-w-0 flex-1">
                    <span id="call-waiting-name" class="block truncate text-sm font-semibold"></span>
                    <span id="call-waiting-type" class="block text-xs text-gray-500"></span>
                </span>
                <span class="flex w-full gap-2">
                    <button type="button" id="call-waiting-decline" class="flex-1 rounded-full bg-red-600 px-3 py-2 text-sm font-semibold text-white">Decline</button>
                    <button type="button" id="call-waiting-answer" class="flex-1 rounded-full bg-green-600 px-3 py-2 text-sm font-semibold text-white">End and answer</button>
                </span>
            </div>
            <p id="call-caption" class="pointer-events-none absolute inset-x-4 bottom-28 z-10 mx-auto hidden max-w-2xl rounded-xl bg-black/70 px-3 py-2 text-center text-sm text-white" aria-live="polite"></p>
            <div id="call-toast" class="absolute left-1/2 top-16 z-30 hidden w-[calc(100%-2rem)] max-w-sm -translate-x-1/2 flex-wrap items-center justify-between gap-2 rounded-xl bg-white px-3 py-2 text-sm text-gray-800 shadow-2xl" role="status">
                <span id="call-toast-text" class="min-w-0 flex-1"></span>
                <button type="button" id="call-toast-action" class="hidden rounded-full bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white"></button>
            </div>

            {{-- Audio-output picker (Speaker/Earpiece/Bluetooth/wired
                 headset), opened from the Speaker control below -
                 populated from the real enumerateDevices() list, not a
                 blind two-way toggle. --}}
            <div id="call-audio-output-menu" class="absolute bottom-40 left-1/2 z-20 hidden w-52 -translate-x-1/2 flex-col rounded-xl bg-white py-1 shadow-2xl"></div>

            {{-- Bottom control grid: 2 rows of icon+label buttons
                 (Speaker/Mute, then More/Share/End) - matches WhatsApp's
                 real voice-call layout rather than a flat row of plain
                 text buttons, and now identical between a voice and a
                 video call (the camera toggle lives in the More menu
                 instead - see the comment there). Unchanged by the
                 N-party rewrite; they now act on every peer connection
                 at once instead of the single one that used to exist. --}}
            {{-- One rounded bar of round icon buttons at the bottom (the WhatsApp pattern): More, Camera (video calls),
                 Speaker, Mute, End. Labels stay for screen readers only. On a video call a tap on the picture hides
                 or shows the bars (see the call-ui-hidden toggle in the script). --}}
            <div class="call-controls relative z-10 shrink-0">
                <div class="call-bar">
                    <button type="button" id="call-more-btn" class="call-control-btn" aria-label="More">
                        <span class="call-control-icon"><x-icons.kebab class="h-5 w-5" /></span>
                        <span class="call-control-label">More</span>
                    </button>
                    <button type="button" id="call-camera-btn" class="call-control-btn hidden" aria-label="Camera">
                        <span class="call-control-icon"><x-icons.video class="h-5 w-5" /></span>
                        <span class="call-control-label">Turn camera off</span>
                    </button>
                    <button type="button" id="call-speaker-btn" class="call-control-btn" aria-label="Speaker">
                        <span class="call-control-icon"><x-icons.volume class="h-5 w-5" /></span>
                        <span class="call-control-label">Speaker</span>
                    </button>
                    <button type="button" id="call-mute-btn" class="call-control-btn" aria-label="Mute">
                        <span class="call-control-icon"><x-icons.mic class="h-5 w-5" /></span>
                        <span class="call-control-label">Mute</span>
                    </button>
                    <button type="button" id="call-end-btn" class="call-control-btn call-end" aria-label="End call">
                        <span class="call-control-icon"><x-icons.phone-end class="h-5 w-5" /></span>
                        <span class="call-control-label">End</span>
                    </button>
                </div>
            </div>

            <p id="call-error" class="absolute bottom-40 left-1/2 z-20 hidden -translate-x-1/2 rounded-lg bg-white px-4 py-2 text-center text-xs font-medium text-red-600 shadow-lg"></p>
        </div>
    </div>

    {{-- Minimised call: the app in a frame under a slim style-B call bar (see minimise() in the script) --}}
    <div id="call-app-frame-wrap" class="fixed inset-x-0 bottom-0 top-[calc(env(safe-area-inset-top)+3.5rem)] z-40 hidden bg-white">
        <iframe id="call-app-frame" class="h-full w-full border-0" title="Meet"></iframe>
    </div>
    <div id="call-mini" class="fixed inset-x-0 top-0 z-[45] hidden h-[calc(env(safe-area-inset-top)+3.5rem)] items-center gap-3 px-3 pt-[env(safe-area-inset-top)] text-white shadow-lg"
         style="background:linear-gradient(160deg,#4f46e5,#9333ea 55%,#db2777)">
        <span id="call-mini-avatar" class="call-glass-avatar h-9 w-9 shrink-0 text-sm"></span>
        <button type="button" id="call-mini-open" class="min-w-0 flex-1 text-left" aria-label="Back to the call">
            <span id="call-mini-name" class="block truncate text-sm font-semibold"></span>
            <span class="text-xs text-white/80">On call <span id="call-mini-timer">00:00</span> - tap to return</span>
        </button>
        <button type="button" id="call-mini-mute" class="rounded-full bg-white/20 p-2" aria-label="Mute"><x-icons.mic class="h-4 w-4" /></button>
        <button type="button" id="call-mini-back" class="rounded-full bg-white/20 p-2" aria-label="Back to the call"><x-icons.chevron-left class="h-4 w-4 rotate-90" /></button>
        <button type="button" id="call-mini-end" class="rounded-full bg-red-600 p-2" aria-label="End call"><x-icons.phone-end class="h-4 w-4" /></button>
    </div>

    {{-- Contact picker for "Add member" - reuses the existing
         /api/v1/contacts list, same data source the forward-picker in
         Chat already draws from. --}}
    <div id="call-add-member-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">
        <div class="w-full max-w-sm rounded-2xl bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                <p class="text-sm font-semibold text-gray-900">Add member</p>
                {{-- SVG, not the &#10005; character - same
                     missing-glyph-on-mobile problem as the More button's
                     old vertical-ellipsis icon. --}}
                <button type="button" id="call-add-member-close" class="rounded-lg p-2 text-gray-400" aria-label="Close">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"></path></svg>
                </button>
            </div>
            <ul id="call-add-member-list" class="max-h-72 overflow-y-auto"></ul>
            <p id="call-add-member-empty" class="hidden px-4 py-8 text-center text-sm text-gray-400">No contacts to add.</p>
        </div>
    </div>

    <script>
        window.MeetCallConfig = @json(config('calls'));
    </script>
    <script>
        // FR-032 - presence heartbeat. Nothing else in the app ever
        // marks the current user online; without this every user's
        // presence_status sits at its 'offline' default forever.
        //
        // Wrapped in DOMContentLoaded because Vite's script tag is
        // type="module" (deferred by spec) - it hasn't run yet, and
        // window.axios doesn't exist yet, at the point the parser
        // reaches this plain inline script further down the page.
        document.addEventListener('DOMContentLoaded', function () {
            function sendHeartbeat() {
                axios.patch('/api/v1/users/presence', { presence_status: 'online' }).catch(function () {});
            }

            sendHeartbeat();
            setInterval(sendHeartbeat, 20000);
        });
    </script>

    <script>
        // Nav-wide unread/request badges (red dot on the Chat and
        // Contacts icons) - site-wide, not just while on those pages,
        // since this script lives in the shared layout every
        // authenticated page uses.
        document.addEventListener('DOMContentLoaded', function () {
            var notifyPrefs = { messages: true, calls: true, meetings: true, contact_requests: true, sound: true };
            try {
                notifyPrefs = Object.assign(notifyPrefs, JSON.parse(document.querySelector('meta[name="notify-preferences"]')?.content || '{}'));
            } catch (e) { /* keep defaults */ }

            function setBadge(key, show) {
                document.querySelectorAll('[data-nav-badge="' + key + '"]').forEach(function (el) {
                    el.classList.toggle('hidden', !show);
                });
            }

            function pollChatBadge() {
                if (!notifyPrefs.messages) {
                    return;
                }

                axios.get('/api/v1/conversations/unread-count').then(function (response) {
                    setBadge('chat', response.data.data.total > 0);
                }).catch(function () {});
            }

            function pollContactsBadge() {
                if (!notifyPrefs.contact_requests) {
                    return;
                }

                axios.get('/api/v1/contacts/requests').then(function (response) {
                    var currentUserUuid = document.querySelector('meta[name="current-user-uuid"]')?.content;
                    var incoming = response.data.data.filter(function (r) { return r.receiver.uuid === currentUserUuid; });
                    setBadge('contacts', incoming.length > 0);
                }).catch(function () {});
            }

            pollChatBadge();
            pollContactsBadge();
            setInterval(pollChatBadge, 15000);
            setInterval(pollContactsBadge, 15000);

            // New-message sound + real browser notification, regardless
            // of which page you're currently on (not just while the
            // chat thread itself is open, which already renders new
            // messages live via its own faster ~1s poll). This is a
            // foreground/tab-open notification only (Notification API,
            // no service worker) - it stops working once the tab/app is
            // fully closed, unlike real Web Push (a separate, bigger
            // backlog item).
            var notifyAudioCtx = null;

            function playMessageDing() {
                var Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) {
                    return;
                }

                try {
                    notifyAudioCtx = notifyAudioCtx || new Ctx();
                    if (notifyAudioCtx.state === 'suspended') {
                        notifyAudioCtx.resume().catch(function () {});
                    }

                    var gain = notifyAudioCtx.createGain();
                    gain.gain.value = 0.2;
                    gain.connect(notifyAudioCtx.destination);

                    var osc = notifyAudioCtx.createOscillator();
                    osc.type = 'sine';
                    osc.frequency.value = 880;
                    osc.connect(gain);
                    osc.start();
                    setTimeout(function () {
                        try { osc.stop(); } catch (e) { /* already stopped */ }
                    }, 180);
                } catch (e) { /* no audio support */ }
            }

            var MESSAGE_TYPE_PREVIEWS = {
                image: 'Photo', video: 'Video', audio: 'Voice message',
                file: 'Document', location: 'Location', contact: 'Contact', one_view: 'Photo/video',
            };

            // Real bug fixed here: for an end-to-end encrypted message
            // (every conversation now, by default) this used to hand
            // lastMessage.body straight to the OS notification - which is
            // raw ciphertext, not text. Mirrors exactly how the chat
            // list's own preview (renderLastMessagePreview() in
            // index.blade.php) decrypts it client-side using the same
            // cached/fetched conversation key - never touches a genuinely
            // plaintext message.
            function messagePreview(lastMessage, conversationId) {
                if (!lastMessage) {
                    return Promise.resolve('New message');
                }
                if (lastMessage.message_type !== 'text') {
                    return Promise.resolve(MESSAGE_TYPE_PREVIEWS[lastMessage.message_type] || 'New message');
                }
                if (lastMessage.encryption_mode !== 'e2e') {
                    return Promise.resolve(lastMessage.body || 'New message');
                }
                if (!window.MeetE2E) {
                    return Promise.resolve('New message');
                }
                return MeetE2E.getConversationKey(conversationId, lastMessage.key_version).then(function (key) {
                    if (!key) return 'New message';
                    return MeetE2E.decryptText(lastMessage.body, lastMessage.iv, key);
                }).catch(function () { return 'New message'; });
            }

            // Already looking at this exact thread (and the tab is
            // actually visible) - it renders the message live already,
            // a popup here would just be redundant/annoying.
            function isViewingConversation(conversationId) {
                return !document.hidden && new RegExp('/chat/' + conversationId + '(?:$|[/?])').test(window.location.pathname);
            }

            // All four notify_* toggles off means the user doesn't want
            // anything pushed to them anymore - a single subscription
            // serves every trigger type indiscriminately, so this is
            // the only point at which unsubscribing (not just not
            // re-subscribing) actually makes sense. Without this, a
            // stale browser-level subscription would just quietly sit
            // there (harmless, since the server-side row was already
            // removed by UserProfileService when the toggles were
            // saved) and get silently re-created here on next load.
            var allNotificationsOff = !notifyPrefs.messages && !notifyPrefs.calls && !notifyPrefs.meetings && !notifyPrefs.contact_requests;

            if (allNotificationsOff) {
                unsubscribeFromPushNotifications();
            } else if (window.Notification && Notification.permission === 'default') {
                Notification.requestPermission().then(subscribeToPushNotifications).catch(function () {});
            } else if (window.Notification && Notification.permission === 'granted') {
                subscribeToPushNotifications();
            }

            // push-notifications.js is a Vite type="module" asset
            // (deferred by spec) - this classic script can genuinely
            // run before it, same hazard as MeetE2E/MeetOffline above.
            function waitForMeetPush(deadline) {
                if (window.MeetPush) return Promise.resolve(window.MeetPush);
                if (Date.now() > deadline) return Promise.resolve(null);
                return new Promise(function (resolve) { setTimeout(resolve, 10); }).then(function () {
                    return waitForMeetPush(deadline);
                });
            }

            function unsubscribeFromPushNotifications() {
                Promise.all([
                    navigator.serviceWorker.getRegistration().catch(function () { return null; }),
                    waitForMeetPush(Date.now() + 5000),
                ]).then(function (results) {
                    var registration = results[0];
                    var push = results[1];
                    if (!push) return null;

                    return push.getDeviceToken(registration).then(function (token) {
                        if (token) {
                            axios.delete('/api/v1/push-subscriptions', { data: { fcm_token: token } }).catch(function () {});
                        }
                        return push.forgetDeviceToken();
                    });
                }).catch(function () {});
            }

            // Real OS-level push via Firebase Cloud Messaging - reaches
            // this device even with every tab closed, unlike the
            // foreground-only sound/Notification above. Only subscribes
            // once granted, and only if Firebase is actually configured
            // (a fresh install without it has no meta tags here at all).
            function subscribeToPushNotifications() {
                if (Notification.permission !== 'granted' || !('serviceWorker' in navigator)) {
                    return;
                }

                Promise.all([navigator.serviceWorker.ready, waitForMeetPush(Date.now() + 5000)]).then(function (results) {
                    var registration = results[0];
                    var push = results[1];
                    if (!push) return null;
                    return push.getDeviceToken(registration);
                }).then(function (token) {
                    if (token) axios.post('/api/v1/push-subscriptions', { fcm_token: token }).catch(function () {});
                }).catch(function () {
                    // Permission granted but subscribe failed (e.g. no
                    // real push service reachable) - foreground
                    // notifications above still work regardless.
                });
            }

            var conversationUnreadSnapshot = {};
            var snapshotPrimed = false;

            function pollForMessageNotifications() {
                if (!notifyPrefs.messages) {
                    return;
                }

                axios.get('/api/v1/conversations').then(function (response) {
                    var anyNewMessage = false;
                    var wasPrimed = snapshotPrimed;

                    var notifyThese = response.data.data.filter(function (conversation) {
                        var previousUnread = conversationUnreadSnapshot[conversation.id];
                        conversationUnreadSnapshot[conversation.id] = conversation.unread_count;

                        if (!wasPrimed || previousUnread === undefined) {
                            return false;
                        }

                        return conversation.unread_count > previousUnread && !isViewingConversation(conversation.id);
                    });

                    snapshotPrimed = true;

                    return Promise.all(notifyThese.map(function (conversation) {
                        anyNewMessage = true;
                        if (!(window.Notification && Notification.permission === 'granted')) {
                            return null;
                        }
                        return messagePreview(conversation.last_message, conversation.id).then(function (body) {
                            new Notification(conversation.title || (conversation.other_user ? conversation.other_user.full_name : 'New message'), {
                                body: body,
                                tag: 'meet-conversation-' + conversation.id,
                            });
                        });
                    })).then(function () {
                        if (anyNewMessage && notifyPrefs.sound) {
                            playMessageDing();
                        }
                    });
                }).catch(function () {});
            }

            pollForMessageNotifications();
            if (window.self === window.top) setInterval(pollForMessageNotifications, 15000); // not twice inside the minimised-call frame
        });
    </script>

    <script>
        // Best-effort orientation lock. The manifest's own `orientation:
        // portrait` (PwaController) already covers an installed/
        // standalone PWA on Android via the OS shell; this JS call is
        // the same lock attempted a second way for that same standalone
        // context (some browsers still need the explicit call, not just
        // the manifest hint) - and it re-attempts on visibility change,
        // since some browsers can drop the lock when a tab is
        // backgrounded and restored. Rejected by design in a plain,
        // non-installed browser tab - there is currently no web
        // platform API that can force portrait there at all; the only
        // real fix for that case is installing the app.
        (function () {
            function attemptOrientationLock() {
                if (!window.screen || !window.screen.orientation || !window.screen.orientation.lock) {
                    return;
                }
                window.screen.orientation.lock('portrait').catch(function () {
                    // Expected/normal outside a standalone/fullscreen
                    // context - nothing else this app can do about it.
                });
            }

            attemptOrientationLock();
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) attemptOrientationLock();
            });
        })();
    </script>

    <script>
        // FR-256 install button. `beforeinstallprompt` (captured in
        // partials/pwa-head.blade.php, loaded in <head> before this
        // script) may already have fired by the time this button
        // exists in the DOM, so check the stored event on load too,
        // not just the live custom-event listener.
        document.addEventListener('DOMContentLoaded', function () {
            var installBtn = document.getElementById('pwa-install-btn');

            // iOS Safari/WebKit never fires beforeinstallprompt at all
            // (no browser-level install API exists there) - "installing"
            // only ever means the user doing Share -> Add to Home
            // Screen themselves, so the button leads to instructions
            // instead of a native prompt. navigator.standalone is iOS's
            // own (non-standard) way of reporting "already installed".
            var isIos = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
            var isStandalone = window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;

            function showInstallButton() {
                if (window.__meetInstallPrompt || (isIos && !isStandalone)) {
                    installBtn.classList.remove('hidden');
                }
            }

            showInstallButton();
            window.addEventListener('meet:pwa-installable', showInstallButton);
            window.addEventListener('meet:pwa-installed', function () { installBtn.classList.add('hidden'); });

            installBtn.addEventListener('click', function () {
                if (window.__meetInstallPrompt) {
                    window.__meetInstallPrompt.prompt();
                    window.__meetInstallPrompt.userChoice.finally(function () {
                        window.__meetInstallPrompt = null;
                        installBtn.classList.add('hidden');
                    });
                    return;
                }

                if (isIos) {
                    document.getElementById('ios-install-instructions').classList.remove('hidden');
                }
            });

            var iosCloseBtn = document.getElementById('ios-install-close');
            if (iosCloseBtn) {
                iosCloseBtn.addEventListener('click', function () {
                    document.getElementById('ios-install-instructions').classList.add('hidden');
                });
            }
        });
    </script>

    <script>
    // Phase 4 - Voice/Video Calls, N-party. Global so an incoming call
    // can be received from any page, per FR-085..FR-107. Polling-based
    // WebRTC signaling (offer/answer/ICE relayed via the DB, no
    // websocket/Node) matches this app's established chat-polling
    // architecture.
    //
    // This used to be a strictly 2-party engine: one RTCPeerConnection,
    // one remote stream, one "other party". A call is genuinely N-party
    // now - a full mesh where every PAIR of participants negotiates its
    // own peer connection, exactly like the meeting room's mesh
    // (Modules/Meetings/resources/views/partials/room-script.blade.php),
    // with peers keyed by the other person's user id. A 1:1 call is
    // simply the N=2 case, and growing beyond it happens live via "Add
    // member" with nobody navigating anywhere.
    document.addEventListener('DOMContentLoaded', function () {
        var cfg = window.MeetCallConfig || { ice_servers: [{ urls: 'stun:stun.l.google.com:19302' }], signal_poll_ms: 400, incoming_call_poll_ms: 2000 };
        var myUserId = parseInt(document.querySelector('meta[name="current-user-id"]')?.content || '0', 10);
        var myName = document.querySelector('meta[name="current-user-name"]')?.content || 'You';
        var notifyCalls = true;
        try {
            notifyCalls = JSON.parse(document.querySelector('meta[name="notify-preferences"]')?.content || '{}').calls !== false;
        } catch (e) { /* keep default */ }

        var overlay = document.getElementById('call-overlay');
        var panels = {
            incoming: document.getElementById('call-incoming'),
            outgoing: document.getElementById('call-outgoing'),
            active: document.getElementById('call-active'),
            ended: document.getElementById('call-ended'),
        };
        // the app inside the minimised-call frame: no second call listener, calls go to the page that holds the call
        var IN_CALL_FRAME = window.self !== window.top;
        var incomingName = document.getElementById('call-incoming-name');
        var incomingType = document.getElementById('call-incoming-type');
        var incomingAvatar = document.getElementById('call-incoming-avatar');
        var outgoingName = document.getElementById('call-outgoing-name');
        var outgoingAvatar = document.getElementById('call-outgoing-avatar');
        var activeName = document.getElementById('call-active-name');
        var activeTimer = document.getElementById('call-active-timer');
        var grid = document.getElementById('call-grid');
        var callError = document.getElementById('call-error');
        var muteBtn = document.getElementById('call-mute-btn');
        var cameraBtn = document.getElementById('call-camera-btn');
        var speakerBtn = document.getElementById('call-speaker-btn');
        var shareBtn = document.getElementById('call-share-btn');
        var screenSharingBadge = document.getElementById('call-screen-sharing-badge');
        var remoteSharingBadge = document.getElementById('call-remote-sharing-badge');
        var connectionStatus = document.getElementById('call-connection-status');
        var connectionDot = document.getElementById('call-connection-dot');
        var connectionLabel = document.getElementById('call-connection-label');
        var moreBtn = document.getElementById('call-more-btn');
        var moreMenu = document.getElementById('call-more-menu');
        var moreReactBtn = document.getElementById('call-more-react-btn');
        var moreAddMemberBtn = document.getElementById('call-more-add-member-btn');
        var moreFlipCameraBtn = document.getElementById('call-more-flip-camera-btn');
        var reactionMenu = document.getElementById('call-reaction-menu');
        var reactionFloatLayer = document.getElementById('call-reaction-float-layer');
        var audioOutputMenu = document.getElementById('call-audio-output-menu');
        var CALL_REACTION_EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];
        var addMemberModal = document.getElementById('call-add-member-modal');
        var addMemberList = document.getElementById('call-add-member-list');
        var addMemberEmpty = document.getElementById('call-add-member-empty');

        var CALL_ICON = {
            mic: '<svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="3" width="6" height="11" rx="3"></rect><path d="M6 11a6 6 0 0 0 12 0M12 17v4M9 21h6"></path></svg>',
            micOff: '<svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5.2A3 3 0 0 1 15 6.5V11a3 3 0 0 1-.2 1.1"></path><path d="M6 11a6 6 0 0 0 8.4 5.5M17.6 13.5A6 6 0 0 0 18 11"></path><path d="M12 17v4M9 21h6M3 3l18 18"></path></svg>',
            video: '<svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="12" height="12" rx="2"></rect><path d="m15 10 6-3.5v11L15 14"></path></svg>',
            videoOff: '<svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M15 8.5V7a1 1 0 0 0-1-1H8m-4.5.5A1 1 0 0 0 3 7v10a1 1 0 0 0 1 1h9a1 1 0 0 0 1-1v-1.5"></path><path d="m15 10 6-3.5v11L15 14"></path><path d="M3 3l18 18"></path></svg>',
            micOffSmall: '<svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5.2A3 3 0 0 1 15 6.5V11a3 3 0 0 1-.2 1.1"></path><path d="M6 11a6 6 0 0 0 8.4 5.5M17.6 13.5A6 6 0 0 0 18 11"></path><path d="M12 17v4M9 21h6M3 3l18 18"></path></svg>',
        };

        // state.peers is the heart of the N-party rewrite: a map of the
        // OTHER participant's user id -> that one pair's connection
        // ({ pc, stream, videoSender, remoteDescSet, iceQueue,
        // reconnectTimer }), replacing the single state.pc/state.
        // remoteStream this file used to hold. state.localStream is
        // acquired once and its tracks are added to every peer.
        var state = null;
        var timers = { incomingPoll: null, statusPoll: null, signalPoll: null, callClock: null, ringTimeout: null };

        // Browsers have no API to read the device's actual system
        // ringtone, so this synthesizes a classic dual-tone ring
        // cadence (440Hz+480Hz, ~2s on / 4s off) with the Web Audio
        // API instead of trying to reach for one - always available,
        // no bundled audio file or licensing to worry about.
        var audioCtx = null;
        var ringtoneInterval = null;
        var ringtoneOscillators = [];
        var ringtoneWanted = false;

        function playRingtone() {
            stopRingtone();
            ringtoneWanted = true;

            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) {
                return; // No Web Audio support - ringing UI still shows, just silently.
            }

            try {
                audioCtx = audioCtx || new Ctx();
                if (audioCtx.state === 'suspended') {
                    // Mobile browsers start every AudioContext suspended
                    // until the page has had a real user gesture, so a
                    // ring arriving on a page nobody has touched yet
                    // stays silent. resume() is attempted here AND again
                    // from the first touch/click anywhere on the page
                    // (see the gesture listener below), which is the only
                    // thing the platform actually allows.
                    audioCtx.resume().catch(function () {});
                }
            } catch (e) {
                return;
            }

            function ringOnce() {
                if (audioCtx.state === 'suspended') {
                    audioCtx.resume().catch(function () {});
                }

                var gain = audioCtx.createGain();
                gain.gain.value = 0.15;
                gain.connect(audioCtx.destination);

                [440, 480].forEach(function (freq) {
                    var osc = audioCtx.createOscillator();
                    osc.type = 'sine';
                    osc.frequency.value = freq;
                    osc.connect(gain);
                    osc.start();
                    ringtoneOscillators.push(osc);
                });

                setTimeout(function () {
                    ringtoneOscillators.forEach(function (osc) {
                        try { osc.stop(); } catch (e) { /* already stopped */ }
                    });
                    ringtoneOscillators = [];
                }, 2000);
            }

            ringOnce();
            ringtoneInterval = setInterval(ringOnce, 6000);
        }

        function stopRingtone() {
            ringtoneWanted = false;
            if (ringtoneInterval) {
                clearInterval(ringtoneInterval);
                ringtoneInterval = null;
            }
            ringtoneOscillators.forEach(function (osc) {
                try { osc.stop(); } catch (e) { /* already stopped */ }
            });
            ringtoneOscillators = [];
        }

        // The one genuinely effective answer to "the device doesn't ring"
        // on mobile: an AudioContext created (or resumed) without user
        // activation is suspended, and NOTHING a page or service worker
        // can do overrides that. Latching onto the first gesture the user
        // makes anywhere means that by the time a call actually arrives,
        // the context is already unlocked and the ringtone starts
        // instantly instead of playing to a suspended output.
        ['pointerdown', 'keydown', 'touchstart'].forEach(function (type) {
            document.addEventListener(type, function () {
                var Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return;
                try {
                    audioCtx = audioCtx || new Ctx();
                    if (audioCtx.state === 'suspended') audioCtx.resume().catch(function () {});
                } catch (e) { /* no audio support */ }
                if (ringtoneWanted && !ringtoneInterval) playRingtone();
            }, { passive: true, capture: true });
        });

        function initials(name) {
            return (name || '?').trim().charAt(0).toUpperCase();
        }

        // the person's photo in a round avatar when there is one (the API already applies their privacy setting), else the initial
        function setAvatar(el, name, photo) {
            el.textContent = '';
            if (photo && /^(https?:\/\/|\/)/.test(photo)) {
                var img = document.createElement('img');
                img.src = photo; img.alt = ''; img.style.cssText = 'width:100%;height:100%;border-radius:50%;object-fit:cover';
                el.appendChild(img);
            } else {
                el.textContent = initials(name);
            }
        }

        function showPanel(name) {
            if (name !== 'ended') clearTimeout(endedTimer);
            overlay.classList.remove('hidden');
            overlay.classList.add('flex');
            Object.keys(panels).forEach(function (key) {
                panels[key].classList.toggle('hidden', key !== name);
            });
        }

        // ---- Participant tiles ----------------------------------------
        // Adaptive layout driven by the real tile count, the same rules
        // the meeting room's video grid uses (1/2/4 are plain Tailwind
        // grid utilities; 3 and 5+ need the .tiles-3/.tiles-many
        // grid-template rules in app.css).
        var GRID_LAYOUT_CLASSES = ['tiles-1', 'tiles-1on1', 'tiles-3', 'tiles-4', 'tiles-many', 'tiles-spotlight', 'grid-cols-1', 'grid-cols-2', 'grid-rows-2', 'sm:grid-rows-1', 'sm:grid-cols-2'];

        // Whoever is currently sharing their screen - yourself or one
        // remote peer - regardless of party size. There is deliberately
        // no support for two people sharing at once (same as Meet/Teams/
        // Jitsi: starting a share while another is live just takes over).
        function currentSpotlightUserId() {
            if (state && state.isScreenSharing) return myUserId;
            if (state && state.remoteSharingUserId != null) return state.remoteSharingUserId;
            // pinned person, or the one talking in speaker view (call-extras)
            return state && extras ? extrasSpotlightUserId() : null;
        }

        function updateGridLayout() {
            var count = grid.children.length;
            grid.classList.remove.apply(grid.classList, GRID_LAYOUT_CLASSES);
            [].forEach.call(grid.querySelectorAll('.call-tile'), function (tile) {
                tile.classList.remove('call-tile--spotlight');
                tile.style.right = '';
            });

            var spotlightId = currentSpotlightUserId();
            if (spotlightId != null) {
                // Screen share fills the ENTIRE call view - like Jitsi/
                // Google Meet/Teams - instead of being just one
                // equally-sized cell in the regular N-up grid (reported
                // as looking "half cut"). Everyone else, including
                // yourself, becomes a small floating thumbnail strip
                // along the bottom - see .tiles-spotlight in app.css.
                // The strip's per-tile `right` offset is assigned here
                // (not in CSS) because there can be any number of them.
                grid.classList.add('tiles-spotlight');
                var spotlightTile = document.getElementById('call-tile-' + String(spotlightId));
                if (spotlightTile) spotlightTile.classList.add('call-tile--spotlight');

                var stripIndex = 0;
                [].forEach.call(grid.children, function (tile) {
                    if (tile === spotlightTile) return;
                    tile.style.right = (1 + stripIndex * 6) + 'rem';
                    stripIndex += 1;
                });
                return;
            }

            if (count <= 1) {
                grid.classList.add('tiles-1', 'grid-cols-1');
            } else if (count === 2) {
                // A 1:1 call - full-screen remote with yourself as a
                // small preview in the corner (the 1.2.3/WhatsApp/
                // FaceTime layout), not an equal split - see .tiles-1on1
                // in app.css for exactly why this changed back.
                grid.classList.add('tiles-1on1');
            } else if (count === 3) {
                grid.classList.add('tiles-3');
            } else if (count === 4) {
                grid.classList.add('tiles-4', 'grid-cols-2', 'grid-rows-2');
            } else {
                grid.classList.add('tiles-many');
            }
        }

        /**
         * Creates (or returns) one participant's tile. Every tile owns
         * its own media element, which is also how audio works with N
         * people: a single shared <audio> element could only ever play
         * one peer's stream, so each peer's stream lives on its own
         * (unmuted) <video> - the same thing the meeting room's tileFor()
         * does.
         */
        function tileFor(userId, name, options) {
            options = options || {};
            var key = String(userId);
            // Remembered independently of the tile's own DOM node: a
            // fast-arriving signal (screen-share start, in particular)
            // can reach a peer before the roster poll has told this
            // client that peer's name at all - see handleRemoteScreenShare(),
            // which used to read the name straight off the tile and show
            // a wrong/placeholder "Someone" in that race.
            if (name && state) {
                state.knownNames = state.knownNames || {};
                state.knownNames[key] = name;
            }
            var existing = document.getElementById('call-tile-' + key);
            if (existing) {
                if (name) {
                    existing.querySelector('.call-tile-name').textContent = name;
                    existing.querySelector('.call-tile-avatar span').textContent = initials(name);
                }
                return existing;
            }

            var tile = document.createElement('div');
            tile.id = 'call-tile-' + key;
            tile.className = 'call-tile';
            tile.dataset.tileUserId = key;
            tile.dataset.tileRemote = options.isSelf ? '0' : '1';

            var video = document.createElement('video');
            video.autoplay = true;
            video.playsInline = true;
            // Your own tile must be muted or you hear yourself echo; every
            // remote tile must NOT be, since it's the only thing playing
            // that person's audio.
            video.muted = !!options.isSelf;
            video.className = 'call-tile-video hidden';
            // A participant joining after an output device was already
            // chosen (mid-call "Add member", or simply the 2nd+ tile
            // created) must play through that same chosen device, not
            // silently fall back to the system default.
            if (selectedAudioOutputId && video.setSinkId) {
                video.setSinkId(selectedAudioOutputId).catch(function () {});
            }
            tile.appendChild(video);

            var avatar = document.createElement('div');
            avatar.className = 'call-tile-avatar';
            avatar.innerHTML = '<span></span>';
            avatar.querySelector('span').textContent = initials(name);
            tile.appendChild(avatar);

            var label = document.createElement('span');
            label.className = 'call-tile-name';
            label.textContent = name || 'Unknown';
            tile.appendChild(label);

            var pin = document.createElement('button');
            pin.type = 'button';
            pin.className = 'call-tile-pin';
            pin.setAttribute('aria-label', 'Pin ' + (name || 'this person'));
            pin.title = 'Pin / unpin';
            pin.innerHTML = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4h6l-1 5 3 3v2H7v-2l3-3-1-5zM12 14v6"></path></svg>';
            pin.addEventListener('click', function (event) { event.stopPropagation(); togglePin(key); });
            tile.appendChild(pin);

            var muted = document.createElement('span');
            muted.className = 'call-tile-muted hidden';
            muted.title = 'Muted';
            muted.innerHTML = CALL_ICON.micOffSmall;
            tile.appendChild(muted);

            grid.appendChild(tile);
            updateGridLayout();
            return tile;
        }

        function removeTile(userId) {
            var tile = document.getElementById('call-tile-' + String(userId));
            if (tile) {
                tile.remove();
                updateGridLayout();
            }
        }

        /**
         * A tile shows live video only while a real, unmuted video track
         * is flowing; otherwise it falls back to the avatar. This is what
         * keeps a voice call looking like a voice call even though every
         * peer connection pre-provisions a video transceiver (so screen
         * share never needs renegotiation) - that transceiver's track
         * exists but stays muted until something actually sends on it.
         */
        function setTileVideoVisible(userId, visible) {
            var tile = document.getElementById('call-tile-' + String(userId));
            if (!tile) return;
            tile.querySelector('.call-tile-video').classList.toggle('hidden', !visible);
            tile.querySelector('.call-tile-avatar').classList.toggle('hidden', visible);
        }

        function attachTileStream(userId, stream) {
            var tile = document.getElementById('call-tile-' + String(userId));
            if (!tile) return;
            var video = tile.querySelector('.call-tile-video');
            if (video.srcObject !== stream) {
                video.srcObject = stream;
            }
            // Autoplay can be refused for an element created after page
            // load; the explicit play() (harmless if it's already
            // playing) is what actually gets remote audio flowing.
            var playing = video.play();
            if (playing && playing.catch) playing.catch(function () {});
        }

        function setTileMuted(userId, isMuted) {
            var tile = document.getElementById('call-tile-' + String(userId));
            if (!tile) return;
            var badge = tile.querySelector('.call-tile-muted');
            badge.classList.toggle('hidden', !isMuted);
            badge.classList.toggle('flex', isMuted);
        }

        function renderSelfTile() {
            var tile = tileFor(myUserId, myName + ' (you)', { isSelf: true });
            attachTileStream(myUserId, state.localStream);
            // Only a video call (or an active screen share) has anything
            // to show in your own tile.
            var hasOwnVideo = !!(state.localStream && state.localStream.getVideoTracks().length);
            setTileVideoVisible(myUserId, hasOwnVideo);
            return tile;
        }

        function hideOverlay() {
            overlay.classList.add('hidden');
            overlay.classList.remove('flex');
            Object.keys(panels).forEach(function (key) { panels[key].classList.add('hidden'); });
            callError.classList.add('hidden');
            grid.innerHTML = '';
            updateGridLayout();
            connectionStatus.classList.add('hidden');
            connectionStatus.classList.remove('flex');
            reactionMenu.classList.add('hidden');
            reactionMenu.classList.remove('flex');
            reactionFloatLayer.innerHTML = '';
            closeMoreMenu();
            addMemberModal.classList.add('hidden');
            addMemberModal.classList.remove('flex');
            screenSharingBadge.classList.add('hidden');
            screenSharingBadge.classList.remove('flex');
            remoteSharingBadge.classList.add('hidden');
            remoteSharingBadge.classList.remove('flex');
        }

        function clearTimers() {
            Object.keys(timers).forEach(function (key) {
                if (timers[key]) {
                    clearInterval(timers[key]);
                    clearTimeout(timers[key]);
                    timers[key] = null;
                }
            });
        }

        function teardown() {
            stopRingtone();
            clearTimers();
            var endedInfo = state && state.startedAt ? {
                name: state.otherName, photo: state.otherPhoto, userId: state.otherUserId, conversationId: state.conversationId,
                type: state.type, seconds: Math.floor((Date.now() - state.startedAt) / 1000),
            } : null;
            leaveMinimised();
            var skipEnded = extras && extras.skipEnded;
            extrasOnTeardown();
            if (state) {
                Object.keys(state.peers).forEach(closePeer);
                if (state.localStream) {
                    state.localStream.getTracks().forEach(function (t) { t.stop(); });
                }
                // Screen share is a separate stream from the camera/mic -
                // without stopping it too, the browser's own "sharing your
                // screen" indicator keeps running after the call ends.
                if (state.screenStream) {
                    state.screenStream.getTracks().forEach(function (t) { t.stop(); });
                }
            }
            state = null;
            currentFacingMode = 'user';
            hideOverlay();
            startIncomingPoll();
            if (endedInfo && !skipEnded) showEnded(endedInfo);
        }

        var callErrorHideTimer = null;

        // Auto-dismisses after a few seconds - this used to sit
        // indefinitely once shown (e.g. "Could not connect the
        // call."), blocking the End-call button underneath it until
        // the whole overlay was torn down.
        function showCallError(message) {
            callError.textContent = message;
            callError.classList.remove('hidden');
            clearTimeout(callErrorHideTimer);
            callErrorHideTimer = setTimeout(hideCallError, 5000);
        }

        function hideCallError() {
            callError.classList.add('hidden');
            clearTimeout(callErrorHideTimer);
            callErrorHideTimer = null;
        }

        // Shows the error over whichever panel is currently visible (the
        // overlay is not hidden by teardown() until after a beat, so the
        // message is actually seen instead of vanishing with the panel).
        function failCall(message) {
            stopRingtone();
            clearTimers();
            showCallError(message);
            setTimeout(teardown, 2500);
        }

        // ---- Mesh peer connections -----------------------------------

        function ensurePeerConnection(peerId) {
            var key = String(peerId);
            if (state.peers[key]) {
                return state.peers[key].pc;
            }

            var pc = new RTCPeerConnection({ iceServers: cfg.ice_servers, iceCandidatePoolSize: cfg.ice_candidate_pool_size || 0 });
            var peer = { pc: pc, stream: new MediaStream(), remoteDescSet: false, iceQueue: [], reconnectTimer: null, videoSender: null, callId: state.id };
            state.peers[key] = peer;

            pc.onicecandidate = function (event) {
                if (event.candidate) {
                    // receiver_id is what makes this N-party: a candidate
                    // belongs to one specific pair inside the call, not to
                    // "the call".
                    axios.post('/api/v1/calls/' + peer.callId + '/ice', { receiver_id: peerId, candidate: JSON.stringify(event.candidate) }).catch(function () {});
                }
            };

            pc.ontrack = function (event) {
                // A track arriving over the pre-provisioned,
                // no-track-yet video transceiver may have no associated
                // MediaStream at all until real media starts flowing -
                // event.streams can be empty here, unlike a track added
                // via addTrack(track, stream).
                (event.streams[0] ? event.streams[0].getTracks() : [event.track]).forEach(function (track) {
                    if (!peer.stream.getTracks().includes(track)) {
                        peer.stream.addTrack(track);
                    }
                });

                attachTileStream(peerId, peer.stream);

                if (event.track.kind === 'audio') {
                    return;
                }

                // Revealing this tile's video has to wait for the track to
                // actually unmute (real RTP arriving), not just for
                // ontrack to fire at negotiation time - otherwise a plain
                // voice call would show a black rectangle for everyone
                // from the moment it connects.
                var reveal = function () { setTileVideoVisible(peerId, true); };
                var conceal = function () { setTileVideoVisible(peerId, false); };

                if (!event.track.muted) reveal();
                event.track.addEventListener('unmute', reveal);
                event.track.addEventListener('mute', conceal);
            };

            // A transient network blip (Wi-Fi handoff, brief packet loss)
            // reports as 'disconnected' before WebRTC gives up - give it a
            // few seconds to self-recover before restarting ICE, and only
            // then on this ONE pair rather than tearing down the whole
            // mesh over a momentary dip on a single leg.
            pc.oniceconnectionstatechange = function () {
                if (!state || !state.peers[key]) return;

                if (pc.iceConnectionState === 'connected' || pc.iceConnectionState === 'completed') {
                    clearTimeout(peer.reconnectTimer);
                    hideCallError();
                } else if (pc.iceConnectionState === 'disconnected') {
                    showCallError('Reconnecting…');
                    clearTimeout(peer.reconnectTimer);
                    peer.reconnectTimer = setTimeout(function () { restartPeerIce(peerId); }, 3000);
                } else if (pc.iceConnectionState === 'failed') {
                    clearTimeout(peer.reconnectTimer);
                    restartPeerIce(peerId);
                }
                updateConnectionStatus();
            };

            // A video m-line always exists on every peer connection, even
            // for a voice call - if there's no camera track, an empty
            // sendrecv transceiver is pre-provisioned so starting screen
            // share later is just a replaceTrack() on this sender, never a
            // fresh SDP negotiation with each peer in turn.
            if (state.localStream) {
                var videoTrack = state.localStream.getVideoTracks()[0];
                var sharedTrack = state.screenStream ? state.screenStream.getVideoTracks()[0] : null;
                peer.videoSender = (sharedTrack || videoTrack)
                    ? pc.addTrack(sharedTrack || videoTrack, state.localStream)
                    : pc.addTransceiver('video', { direction: 'sendrecv' }).sender;

                state.localStream.getAudioTracks().forEach(function (track) { pc.addTrack(track, state.localStream); });
            }

            return pc;
        }

        function connectToPeer(peerId) {
            // Deterministic initiator rule, same as the meeting room's:
            // the lower user id offers, so two clients discovering each
            // other on their own roster polls at the same moment don't
            // both fire an offer and glare.
            if (Number(myUserId) >= Number(peerId)) {
                ensurePeerConnection(peerId);
                return;
            }

            var pc = ensurePeerConnection(peerId);
            var callId = state.id;
            pc.createOffer().then(function (offer) {
                return pc.setLocalDescription(offer);
            }).then(function () {
                return axios.post('/api/v1/calls/' + callId + '/offer', { receiver_id: peerId, sdp: JSON.stringify(pc.localDescription) });
            }).catch(function () { showCallError('Could not connect to a participant.'); });
        }

        function restartPeerIce(peerId) {
            var peer = state && state.peers[String(peerId)];
            // Only the lower id restarts, matching connectToPeer's rule -
            // otherwise both ends renegotiate at once.
            if (!peer || Number(myUserId) >= Number(peerId)) {
                return;
            }

            peer.pc.createOffer({ iceRestart: true }).then(function (offer) {
                return peer.pc.setLocalDescription(offer);
            }).then(function () {
                return axios.post('/api/v1/calls/' + peer.callId + '/offer', { receiver_id: peerId, sdp: JSON.stringify(peer.pc.localDescription) });
            }).catch(function () {});
        }

        function closePeer(key) {
            var peer = state && state.peers[key];
            if (!peer) return;
            clearTimeout(peer.reconnectTimer);
            try { peer.pc.close(); } catch (e) { /* already closed */ }
            delete state.peers[key];
        }

        function teardownPeer(peerId) {
            closePeer(String(peerId));
            removeTile(peerId);
            // A peer who was spotlighted (screen-sharing) and then left
            // outright - rather than sending an explicit 'stopped' signal
            // - must not leave the grid pointed at a tile that no longer
            // exists (see currentSpotlightUserId()).
            if (state && state.remoteSharingUserId === peerId) {
                state.remoteSharingUserId = null;
                updateGridLayout();
            }
        }

        function flushIceQueue(peerId) {
            var peer = state.peers[String(peerId)];
            if (!peer) return;
            peer.iceQueue.forEach(function (candidate) {
                peer.pc.addIceCandidate(new RTCIceCandidate(candidate)).catch(function () {});
            });
            peer.iceQueue = [];
        }

        function updateConnectionStatus() {
            if (!state) return;
            var keys = Object.keys(state.peers);
            // "Connected" means every leg of the mesh this client holds is
            // up; one struggling peer still reads as connecting.
            var allConnected = keys.length > 0 && keys.every(function (key) {
                var s = state.peers[key].pc.iceConnectionState;
                return s === 'connected' || s === 'completed';
            });
            connectionDot.className = 'h-1.5 w-1.5 rounded-full ' + (allConnected ? 'bg-emerald-400' : 'bg-amber-400');
            connectionLabel.textContent = allConnected ? 'Connected' : 'Connecting…';
        }

        // ---- Entering / leaving the active call ----------------------

        function getLocalMedia(type) {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                // getUserMedia only exists in a secure context - the usual
                // real-world cause is reaching the app over plain http://
                // on a phone (http://192.168.x.x), where the whole call
                // feature is unavailable at the platform level rather than
                // broken. Say so instead of failing with a bare TypeError.
                return Promise.reject(new Error(window.isSecureContext
                    ? 'This browser cannot access the camera or microphone.'
                    : 'Calls need a secure (https) connection on this device.'));
            }
            return navigator.mediaDevices.getUserMedia({ audio: true, video: type === 'video' });
        }

        function startCallClock() {
            state.startedAt = Date.now();
            clearInterval(timers.callClock);
            timers.callClock = setInterval(function () {
                var seconds = Math.floor((Date.now() - state.startedAt) / 1000);
                var mins = String(Math.floor(seconds / 60)).padStart(2, '0');
                var secs = String(seconds % 60).padStart(2, '0');
                activeTimer.textContent = mins + ':' + secs;
                if (miniTimer) miniTimer.textContent = mins + ':' + secs;
            }, 1000);
        }

        function updateActiveHeader() {
            var others = Object.keys(state.peers).length;
            var tiles = grid.querySelectorAll('[data-tile-remote="1"]').length;
            var count = Math.max(others, tiles);
            if (count > 1) {
                activeName.textContent = 'Group call · ' + (count + 1) + ' people';
            } else {
                activeName.textContent = state.otherName || 'Unknown';
            }
        }

        // Video calls: a tap on the picture hides / shows the top bar and the control bar (WhatsApp pattern);
        // they come back by themselves after a menu or an error needs them.
        document.getElementById('call-grid').addEventListener('click', function () {
            if (!state || state.type !== 'video') return;
            document.getElementById('call-active').classList.toggle('call-ui-hidden');
        });

        function showActive() {
            document.getElementById('call-active').classList.remove('call-ui-hidden');
            stopRingtone();
            if (timers.ringTimeout) {
                clearTimeout(timers.ringTimeout);
                timers.ringTimeout = null;
            }
            updateActiveHeader();
            activeTimer.textContent = '00:00';
            cameraBtn.classList.toggle('hidden', state.type !== 'video');
            setMuteBtnUi(false);
            setCameraBtnUi(true);
            setShareBtnUi(false);
            connectionStatus.classList.remove('hidden');
            connectionStatus.classList.add('flex');
            updateConnectionStatus();
            updateFlipCameraAvailability();
            showPanel('active');
            startCallClock();
            extrasOnActive();
        }

        /**
         * Acquire local media and go live. Reached three ways, all
         * identical from here on: the caller's poll seeing the call
         * accepted, the receiver tapping Accept, and somebody who was
         * added to a call already in progress (see autoJoinCall()).
         */
        function becomeActive() {
            if (!state || state.joining || state.joined) {
                return;
            }
            state.joining = true;

            getLocalMedia(state.type).then(function (stream) {
                state.localStream = stream;
                state.joined = true;
                state.joining = false;
                showActive();
                renderSelfTile();
                startSignalPoll();
                startStatusPoll();
            }).catch(function (error) {
                state.joining = false;
                axios.post('/api/v1/calls/' + state.id + '/end').catch(function () {});
                failCall(error && error.message && error.message.indexOf('secure') !== -1
                    ? error.message
                    : 'Camera/microphone permission denied.');
            });
        }

        // ---- Polling -------------------------------------------------

        function startIncomingPoll() {
            if (IN_CALL_FRAME || timers.incomingPoll) {
                return;
            }
            timers.incomingPoll = setInterval(checkForIncomingCall, cfg.incoming_call_poll_ms);
        }

        function checkForIncomingCall() {
            if (state) {
                return;
            }
            axios.get('/api/v1/calls/incoming').then(function (response) {
                var call = response.data.data;
                if (!call || state) {
                    return;
                }

                // Two genuinely different cases behind one poll:
                // a call still RINGING for me means ring the device and
                // show Accept/Decline; a call already ACCEPTED that I'm a
                // participant of means somebody just added me to a
                // conversation in progress - join straight in, no ringing,
                // no navigation (see CallService::addParticipant()).
                if (call.call_status === 'ringing') {
                    openIncoming(call);
                } else if (call.call_status === 'accepted') {
                    autoJoinCall(call);
                }
            }).catch(function () {});
        }

        // setInterval is throttled hard (or suspended outright) while a
        // mobile tab is backgrounded, so a call that arrived while the
        // phone was asleep would otherwise not surface until the next
        // slow tick after wake. Checking immediately on wake is what
        // makes the in-app incoming-call UI appear the moment the user
        // looks at their screen.
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                return;
            }
            if (!state) {
                checkForIncomingCall();
            } else if (ringtoneWanted && !ringtoneInterval) {
                playRingtone();
            }
        });

        function openIncoming(call) {
            clearInterval(timers.incomingPoll);
            timers.incomingPoll = null;
            state = { id: call.id, type: call.call_type, role: 'receiver', otherName: call.caller.full_name, otherUserId: call.caller.id, otherPhoto: call.caller.profile_photo, conversationId: call.conversation_id, peers: {}, cursor: { signal: 0, ice: 0 } };
            incomingName.textContent = state.otherName || 'Unknown';
            setAvatar(incomingAvatar, state.otherName, call.caller && call.caller.profile_photo);
            incomingType.textContent = (call.call_type === 'video' ? 'Video call…' : 'Voice call…');
            showPanel('incoming');
            if (notifyCalls) {
                playRingtone();
            }

            // FR-097-adjacent: a call nobody answers must resolve to
            // "missed" for the person seeing it ring, not just eventually
            // for the caller. The server-side MarkMissedCallsJob backstop
            // runs on a once-a-minute cron, so relying on it alone left
            // the ringing UI (and ringtone) live for up to ~90s past the
            // real 30s ring_timeout_seconds.
            //
            // Real bug fixed here: this used to always run teardown()
            // via `.catch(() => {}).then(teardown)` even when the /reject
            // call FAILED - which it always does once the call is no
            // longer 'ringing' server-side (CallService::reject() 409s).
            // If Accept was tapped close to the timeout (getUserMedia's
            // permission prompt + the accept round-trip can easily take
            // a few seconds on mobile), this fired right as the call
            // connected and hung it straight back up - "Accept also ends
            // the call". Now it only tears down THIS client when the
            // reject genuinely succeeded (call was still really ringing),
            // and never fires at all once Accept has been tapped.
            timers.ringTimeout = setTimeout(function () {
                if (!state || state.joining || state.joined) {
                    return;
                }
                axios.post('/api/v1/calls/' + state.id + '/reject').then(teardown).catch(function () {});
            }, (call.ring_timeout_seconds || 30) * 1000);
        }

        /**
         * Added to a live call by somebody else's "Add member" tap. No
         * ringing screen and no navigation - straight into the grid,
         * which is the entire point of calls being natively N-party now
         * (this used to be a redirect to a freshly created Meeting).
         */
        function autoJoinCall(call) {
            clearInterval(timers.incomingPoll);
            timers.incomingPoll = null;
            var others = (call.participants || []).filter(function (p) { return !p.is_me; });
            state = {
                id: call.id,
                type: call.call_type,
                role: 'participant',
                otherName: others.length === 1 ? others[0].full_name : (call.caller && call.caller.full_name),
                peers: {},
                cursor: { signal: 0, ice: 0 },
            };
            becomeActive();
        }

        function startStatusPoll() {
            if (timers.statusPoll) {
                return;
            }
            timers.statusPoll = setInterval(pollCallStatus, cfg.incoming_call_poll_ms);
        }

        /**
         * One poll doing two jobs: call-status transitions (the other
         * side accepted/rejected/hung up) and - new - the participant
         * ROSTER. The roster is what discovers somebody who has just
         * been added (spin up a peer connection and a tile for them) and
         * somebody who has just left (tear down just that one peer),
         * which is how everyone's screen updates with no navigation.
         */
        function pollCallStatus() {
            if (!state) {
                return;
            }

            var polledCallId = state.id;
            axios.get('/api/v1/calls/' + polledCallId).then(function (response) {
                var call = response.data.data;
                // an answer about an earlier call (call waiting switches calls at once) is not about this one
                if (!state || state.id !== polledCallId) {
                    return;
                }

                if (['rejected', 'cancelled', 'ended', 'missed'].indexOf(call.call_status) !== -1) {
                    teardown();
                    return;
                }

                if (call.call_status === 'accepted' && !state.joined && !state.joining) {
                    becomeActive();
                    return;
                }

                if (!state.joined) {
                    return;
                }

                reconcileParticipants(call.participants || []);
            }).catch(function (error) {
                if (!state || state.id !== polledCallId) {
                    return;
                }
                if (error.response && (error.response.status === 404 || error.response.status === 403)) {
                    // 403 here means the server no longer considers this
                    // client a participant (e.g. it left from another
                    // device) - the same dead end as a 404.
                    teardown();
                }
            });
        }

        function reconcileParticipants(participants) {
            var seen = {};

            participants.forEach(function (p) {
                var key = String(p.user_id);
                seen[key] = true;

                if (p.user_id === myUserId) {
                    return;
                }

                tileFor(p.user_id, p.full_name);

                // Self-heal the rare race where a screen-share signal
                // arrived before this client had ever learned the
                // sharer's name (tileFor's cache above didn't exist yet
                // at that moment) - the badge would otherwise be stuck on
                // the "Someone" placeholder for the rest of the share.
                if (state.remoteSharingUserId === p.user_id) {
                    remoteSharingBadge.textContent = p.full_name + ' is sharing their screen';
                }

                if (!state.peers[key]) {
                    connectToPeer(p.user_id);
                }

                // Their real mute/camera state, polled the same way call
                // status is - previously a purely local toggle nobody else
                // ever saw. Per person now, not "the other party".
                setTileMuted(p.user_id, p.audio_enabled === false);
                if (p.video_enabled === false) {
                    setTileVideoVisible(p.user_id, false);
                }
            });

            Object.keys(state.peers).forEach(function (key) {
                if (!seen[key]) {
                    teardownPeer(key);
                }
            });

            // A tile can outlive its peer entry (or vice versa) if a
            // connection failed to establish - the roster is the authority
            // on who should be on screen.
            grid.querySelectorAll('[data-tile-remote="1"]').forEach(function (tile) {
                if (!seen[tile.dataset.tileUserId]) {
                    tile.remove();
                    updateGridLayout();
                }
            });

            updateActiveHeader();
            updateConnectionStatus();
        }

        function startSignalPoll() {
            if (timers.signalPoll) {
                return;
            }
            timers.signalPoll = setInterval(pollSignals, cfg.signal_poll_ms);
        }

        function pollSignals() {
            if (!state || !state.joined) {
                return;
            }

            var cursor = state.cursor;
            var polledCallId = state.id;
            axios.get('/api/v1/calls/' + polledCallId + '/signals', {
                params: { after_signal_id: cursor.signal, after_ice_id: cursor.ice },
            }).then(function (response) {
                if (!state || state.id !== polledCallId || state.ending) {
                    return;
                }
                var data = response.data.data;

                data.signals.forEach(function (signal) {
                    // two polls can overlap on a slow connection: never apply the same signal twice
                    // (a second copy of an answer used to show "Could not connect the call.")
                    if (signal.id <= cursor.signal) return;
                    cursor.signal = Math.max(cursor.signal, signal.id);
                    // sender_id is what makes an N-party mesh workable at
                    // all: it says which of this client's several peer
                    // connections the signal belongs to.
                    var peerId = signal.sender_id;

                    // A reaction's payload is a plain emoji string, not
                    // JSON like offer/answer's SDP - handled separately so
                    // it never hits the JSON.parse below.
                    if (signal.signal_type === 'reaction') {
                        showReaction(signal.payload);
                        return;
                    }

                    // Same reason - a plain string payload
                    // ('started'/'stopped'). Also the AUTHORITATIVE signal
                    // for revealing/concealing a peer's video on a voice
                    // call: replaceTrack(null) doesn't reliably fire the
                    // receiving track's own 'mute' event across browsers,
                    // so ontrack's listeners alone can't tell when sharing
                    // stops.
                    if (signal.signal_type === 'screen-share') {
                        handleRemoteScreenShare(peerId, signal.payload);
                        return;
                    }

                    if (signal.signal_type === 'event') {
                        try { handleCallEvent(peerId, JSON.parse(signal.payload)); } catch (e) { /* ignore a bad event */ }
                        return;
                    }

                    var payload = JSON.parse(signal.payload);
                    var pc = ensurePeerConnection(peerId);
                    var peer = state.peers[String(peerId)];

                    // No `!remoteDescSet` guard - an ICE restart sends a
                    // brand new offer/answer on an already-negotiated
                    // connection and must still be processed.
                    if (signal.signal_type === 'offer') {
                        tileFor(peerId, null);
                        pc.setRemoteDescription(new RTCSessionDescription(payload)).then(function () {
                            peer.remoteDescSet = true;
                            flushIceQueue(peerId);
                            return pc.createAnswer();
                        }).then(function (answer) {
                            return pc.setLocalDescription(answer);
                        }).then(function () {
                            return axios.post('/api/v1/calls/' + polledCallId + '/answer', { receiver_id: peerId, sdp: JSON.stringify(pc.localDescription) });
                        }).catch(function () { showCallError('Could not connect the call.'); });
                    } else if (signal.signal_type === 'answer') {
                        pc.setRemoteDescription(new RTCSessionDescription(payload)).then(function () {
                            peer.remoteDescSet = true;
                            flushIceQueue(peerId);
                        }).catch(function () { showCallError('Could not connect the call.'); });
                    }
                });

                data.ice_candidates.forEach(function (item) {
                    cursor.ice = Math.max(cursor.ice, item.id);
                    var peerId = String(item.sender_id);
                    var candidate = JSON.parse(item.candidate);
                    var peer = state.peers[peerId];

                    if (peer && peer.remoteDescSet) {
                        peer.pc.addIceCandidate(new RTCIceCandidate(candidate)).catch(function () {});
                    } else if (peer) {
                        peer.iceQueue.push(candidate);
                    }
                });
            }).catch(function () {});
        }

        function handleRemoteScreenShare(peerId, sharingState) {
            var tile = document.getElementById('call-tile-' + String(peerId));
            var name = (state.knownNames && state.knownNames[String(peerId)])
                || (tile ? tile.querySelector('.call-tile-name').textContent : null)
                || 'Someone';

            if (sharingState === 'started') {
                remoteSharingBadge.textContent = name + ' is sharing their screen';
                remoteSharingBadge.classList.remove('hidden');
                remoteSharingBadge.classList.add('flex');
                setTileVideoVisible(peerId, true);
                state.remoteSharingUserId = peerId;
            } else {
                remoteSharingBadge.classList.add('hidden');
                remoteSharingBadge.classList.remove('flex');
                if (state.type !== 'video') setTileVideoVisible(peerId, false);
                if (state.remoteSharingUserId === peerId) state.remoteSharingUserId = null;
            }
            updateGridLayout();
        }

        // ---- Reactions ----------------------------------------------

        function showReaction(emoji) {
            var el = document.createElement('span');
            el.className = 'call-reaction-float absolute bottom-4 text-3xl';
            el.style.left = (10 + Math.random() * 70) + '%';
            el.textContent = emoji;
            reactionFloatLayer.appendChild(el);
            var animation = el.animate(
                [{ transform: 'translateY(0)', opacity: 1 }, { transform: 'translateY(-160px)', opacity: 0 }],
                { duration: 1600, easing: 'ease-out' },
            );
            animation.onfinish = function () { el.remove(); };
        }

        function closeMoreMenu() {
            moreMenu.classList.add('hidden');
            moreMenu.classList.remove('flex');
        }

        function closeReactionMenu() {
            reactionMenu.classList.add('hidden');
            reactionMenu.classList.remove('flex');
        }

        moreBtn.addEventListener('click', function () {
            var isOpen = moreMenu.classList.contains('flex');
            closeReactionMenu();
            moreMenu.classList.toggle('hidden', isOpen);
            moreMenu.classList.toggle('flex', !isOpen);
        });

        moreReactBtn.addEventListener('click', function () {
            closeMoreMenu();
            var isOpen = reactionMenu.classList.contains('flex');
            reactionMenu.classList.toggle('hidden', isOpen);
            reactionMenu.classList.toggle('flex', !isOpen);
        });

        moreAddMemberBtn.addEventListener('click', function () {
            closeMoreMenu();
            openAddMemberPicker();
        });

        CALL_REACTION_EMOJIS.forEach(function (emoji) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'p-1 text-xl leading-none';
            btn.textContent = emoji;
            btn.addEventListener('click', function () {
                closeReactionMenu();
                showReaction(emoji);
                if (state && state.id) {
                    axios.post('/api/v1/calls/' + state.id + '/reaction', { emoji: emoji }).catch(function () {});
                }
            });
            reactionMenu.appendChild(btn);
        });

        // ---- Add member ---------------------------------------------

        function openAddMemberPicker() {
            addMemberList.innerHTML = '';
            addMemberEmpty.classList.add('hidden');
            addMemberModal.classList.remove('hidden');
            addMemberModal.classList.add('flex');

            axios.get('/api/v1/contacts').then(function (response) {
                var contacts = (response.data.data || []).filter(function (contact) {
                    // Somebody already in the call isn't addable.
                    return !state || !state.peers[String(contact.contact_user_id)];
                });
                if (!contacts.length) {
                    addMemberEmpty.classList.remove('hidden');
                    return;
                }
                contacts.forEach(function (contact) {
                    var name = contact.nickname || contact.user?.full_name || 'Unknown';
                    var li = document.createElement('li');
                    li.className = 'border-b border-gray-50 last:border-0';
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'flex w-full items-center gap-3 px-4 py-3 text-left';
                    btn.innerHTML =
                        '<span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700">' + initials(name) + '</span>' +
                        '<span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900"></span>';
                    btn.querySelector('span.flex-1').textContent = name;
                    btn.addEventListener('click', function () {
                        if (!state || !state.id) {
                            return;
                        }
                        addMemberModal.classList.add('hidden');
                        addMemberModal.classList.remove('flex');

                        // No redirect, no new Meeting, no call ending: the
                        // response is the same call with one more
                        // participant, and everyone's roster poll (this
                        // client's included) turns that into a new tile.
                        axios.post('/api/v1/calls/' + state.id + '/add-member', { user_id: contact.contact_user_id }).then(function (response) {
                            reconcileParticipants(response.data.data.participants || []);
                        }).catch(function (error) {
                            showCallError(error.response && error.response.status === 409
                                ? (error.response.data.message || 'That person is on another call right now.')
                                : 'Could not add that member.');
                        });
                    });
                    li.appendChild(btn);
                    addMemberList.appendChild(li);
                });
            }).catch(function () {
                addMemberEmpty.classList.remove('hidden');
            });
        }

        document.getElementById('call-add-member-close').addEventListener('click', function () {
            addMemberModal.classList.add('hidden');
            addMemberModal.classList.remove('flex');
        });

        // ---- Starting / answering ------------------------------------

        function startCall(receiverId, type, displayName, conversationId) {
            if (state) {
                return;
            }
            state = { role: 'caller', type: type, otherName: displayName, otherUserId: receiverId, conversationId: conversationId || null, peers: {}, cursor: { signal: 0, ice: 0 } };
            outgoingName.textContent = displayName || 'Unknown';
            outgoingAvatar.textContent = initials(displayName);
            showPanel('outgoing');
            playRingtone();

            // conversation_id lets the call get logged as a row in that
            // chat's own timeline once it ends (see
            // CallService::logCallInConversation()).
            axios.post('/api/v1/calls/' + type + '/start', { receiver_id: receiverId, conversation_id: conversationId || undefined }).then(function (response) {
                state.id = response.data.data.id;
                var rcv = response.data.data.receiver;
                if (rcv && rcv.profile_photo) { setAvatar(outgoingAvatar, displayName, rcv.profile_photo); state.otherPhoto = rcv.profile_photo; }
                if (!state.conversationId && response.data.data.conversation_id) state.conversationId = response.data.data.conversation_id;
                startStatusPoll();

                timers.ringTimeout = setTimeout(function () {
                    axios.post('/api/v1/calls/' + state.id + '/end').catch(function () {}).then(teardown);
                }, (response.data.data.ring_timeout_seconds || 30) * 1000);
            }).catch(function (error) {
                var message = error.response && error.response.status === 409
                    ? 'That user is on another call right now.'
                    : 'Could not start the call.';
                failCall(message);
            });
        }

        function acceptIncomingCall() {
            if (!state || !state.id) {
                return;
            }
            var callId = state.id;
            stopRingtone();
            // Cancel the ring-timeout the moment Accept is tapped, rather
            // than waiting for getLocalMedia()/becomeActive() to fully
            // finish - see its own docblock for the bug this used to
            // cause when that took a few seconds on mobile.
            if (timers.ringTimeout) {
                clearTimeout(timers.ringTimeout);
                timers.ringTimeout = null;
            }
            axios.post('/api/v1/calls/' + callId + '/accept').then(function () {
                becomeActive();
            }).catch(function () {
                axios.post('/api/v1/calls/' + callId + '/reject').catch(function () {});
                failCall('Could not answer the call.');
            });
        }

        document.getElementById('call-accept-btn').addEventListener('click', acceptIncomingCall);

        document.getElementById('call-reject-btn').addEventListener('click', function () {
            if (!state) {
                return;
            }
            axios.post('/api/v1/calls/' + state.id + '/reject').catch(function () {}).then(teardown);
        });

        document.getElementById('call-cancel-btn').addEventListener('click', function () {
            if (!state || !state.id) {
                teardown();
                return;
            }
            axios.post('/api/v1/calls/' + state.id + '/end').catch(function () {}).then(teardown);
        });

        // "End" is really "leave" now: with 3+ people the call carries on
        // for everyone else (the server ends it once fewer than two are
        // left - see CallService::end()). Either way this client is done,
        // so it tears down locally regardless.
        document.getElementById('call-end-btn').addEventListener('click', function () {
            if (!state) {
                return;
            }
            // hanging up: stop answering signals at once (the end request can take a moment)
            state.ending = true;
            clearInterval(timers.signalPoll);
            timers.signalPoll = null;
            axios.post('/api/v1/calls/' + state.id + '/end').catch(function () {}).then(teardown);
        });

        // ---- Controls ------------------------------------------------

        function setMuteBtnUi(isMuted) {
            muteBtn.classList.toggle('call-control-btn--active', isMuted);
            muteBtn.querySelector('.call-control-icon').innerHTML = isMuted ? CALL_ICON.micOff : CALL_ICON.mic;
            muteBtn.querySelector('.call-control-label').textContent = isMuted ? 'Unmute' : 'Mute';
        }

        function setCameraBtnUi(isOn) {
            cameraBtn.querySelector('.call-control-label').textContent = isOn ? 'Turn camera off' : 'Turn camera on';
            cameraBtn.querySelector('.call-control-icon').innerHTML = isOn ? CALL_ICON.video : CALL_ICON.videoOff;
            cameraBtn.classList.toggle('call-control-btn--active', !isOn);
        }

        function setShareBtnUi(isSharing) {
            shareBtn.classList.toggle('call-control-btn--active', isSharing);
            shareBtn.querySelector('.call-control-label').textContent = isSharing ? 'Stop' : 'Share';
        }

        muteBtn.addEventListener('click', function () {
            if (!state || !state.localStream) {
                return;
            }
            var audioTrack = state.localStream.getAudioTracks()[0];
            if (!audioTrack) {
                return;
            }
            // One track shared by every peer connection, so muting once
            // mutes you for everybody - no per-peer loop needed.
            audioTrack.enabled = !audioTrack.enabled;
            setMuteBtnUi(!audioTrack.enabled);
            setTileMuted(myUserId, !audioTrack.enabled);
            if (state.id) {
                axios.patch('/api/v1/calls/' + state.id + '/media', { audio_enabled: audioTrack.enabled }).catch(function () {});
            }
        });

        cameraBtn.addEventListener('click', function () {
            closeMoreMenu();
            if (!state || !state.localStream) {
                return;
            }
            var videoTrack = state.localStream.getVideoTracks()[0];
            if (!videoTrack) {
                return;
            }
            videoTrack.enabled = !videoTrack.enabled;
            setCameraBtnUi(videoTrack.enabled);
            setTileVideoVisible(myUserId, videoTrack.enabled);
            if (state.id) {
                axios.patch('/api/v1/calls/' + state.id + '/media', { video_enabled: videoTrack.enabled }).catch(function () {});
            }
        });

        // Browsers give web apps no direct "route to earpiece vs speaker
        // vs Bluetooth" API (that's native-app-only on iOS) - the
        // closest portable approximation is HTMLMediaElement.setSinkId()
        // (Chrome/Edge/Android only, not Safari - see the capability
        // check below, which HIDES this control entirely where it can't
        // work rather than showing a button that does nothing) against
        // whichever 'audiooutput' device enumerateDevices() reports.
        // This used to be a blind two-way toggle guessing "speaker" vs
        // "earpiece" from a label regex; it's now a real picker over
        // every actual output device (Jitsi's own Audio settings panel
        // is the reference), applied to every participant tile's own
        // media element since with N people there's no single shared
        // audio element any more.
        var selectedAudioOutputId = null;

        function audioOutputSupported() {
            var probe = document.createElement('video');
            return typeof probe.setSinkId === 'function';
        }

        if (!audioOutputSupported()) {
            speakerBtn.classList.add('hidden');
        }

        function friendlyOutputLabel(device, isFirst) {
            if (/bluetooth/i.test(device.label)) return 'Bluetooth headset';
            if (/headset|headphone|wired|usb/i.test(device.label)) return 'Headset';
            if (/speaker/i.test(device.label)) return 'Speaker';
            if (/ear-?piece|receiver/i.test(device.label)) return 'Earpiece';
            if (isFirst) return 'Default';
            return device.label || 'Audio output';
        }

        function applyAudioOutput(deviceId) {
            selectedAudioOutputId = deviceId;
            [].slice.call(grid.querySelectorAll('.call-tile-video')).forEach(function (el) {
                if (el.setSinkId) el.setSinkId(deviceId).catch(function () {});
            });
        }

        function closeAudioOutputMenu() {
            audioOutputMenu.classList.add('hidden');
            audioOutputMenu.classList.remove('flex');
        }

        function openAudioOutputMenu() {
            navigator.mediaDevices.enumerateDevices().then(function (devices) {
                var outputs = devices.filter(function (d) { return d.kind === 'audiooutput'; });
                if (!outputs.length) return;

                audioOutputMenu.innerHTML = '';
                outputs.forEach(function (device, index) {
                    var isActive = selectedAudioOutputId ? device.deviceId === selectedAudioOutputId : index === 0;
                    var option = document.createElement('button');
                    option.type = 'button';
                    option.className = 'flex items-center justify-between px-4 py-3 text-left text-sm font-medium ' + (isActive ? 'text-brand-700' : 'text-gray-700');
                    option.textContent = friendlyOutputLabel(device, index === 0);
                    if (isActive) {
                        var check = document.createElement('span');
                        check.textContent = '✓';
                        option.appendChild(check);
                    }
                    option.addEventListener('click', function () {
                        applyAudioOutput(device.deviceId);
                        closeAudioOutputMenu();
                    });
                    audioOutputMenu.appendChild(option);
                });

                audioOutputMenu.classList.remove('hidden');
                audioOutputMenu.classList.add('flex');
            }).catch(function () {});
        }

        speakerBtn.addEventListener('click', function () {
            var isOpen = audioOutputMenu.classList.contains('flex');
            closeMoreMenu();
            closeReactionMenu();
            if (isOpen) {
                closeAudioOutputMenu();
            } else {
                openAudioOutputMenu();
            }
        });

        // ---- Screen share --------------------------------------------
        // Every peer connection's video sender was pre-provisioned with
        // no track specifically so this is always just a replaceTrack()
        // per peer, never a fresh SDP negotiation with each of them in
        // turn. The user's own camera is never turned on by this - only
        // the shared screen becomes the outgoing video.

        /**
         * Honest feature detection with an honest message for each
         * distinct reason, replacing the single catch-all "not supported
         * in this browser" (which was reported from a phone as the app
         * claiming the *device* wasn't supported).
         *
         * The important finding: mobile Safari and Chrome for Android do
         * not implement getDisplayMedia AT ALL - there is no screen
         * capture available to any web page on those platforms, which is
         * a real platform limitation and not something this app can work
         * around. Google Meet/Teams/Zoom don't screen-share from mobile
         * WEB either; they do it from their native apps. What this code
         * can do is say which of the three actual situations applies.
         */
        function screenShareUnavailableReason() {
            if (!window.isSecureContext) {
                return 'Screen sharing needs a secure (https) connection.';
            }
            if (!navigator.mediaDevices || typeof navigator.mediaDevices.getDisplayMedia !== 'function') {
                // Deliberately NOT gated on "is this a PWA/standalone
                // window" or on any user-agent sniffing: an installed
                // desktop PWA DOES support getDisplayMedia, and an
                // earlier blanket check would have wrongly blocked it.
                // The only thing tested is whether the API itself exists.
                return 'Your browser doesn\'t support screen sharing. On phones and tablets, no mobile browser offers it - use the desktop app or a computer to share your screen.';
            }
            return null;
        }

        function startScreenShare() {
            if (!state || !state.joined) {
                return;
            }

            var reason = screenShareUnavailableReason();
            if (reason) {
                showCallError(reason);
                return;
            }

            navigator.mediaDevices.getDisplayMedia({ video: true }).then(function (stream) {
                state.screenStream = stream;
                var screenTrack = stream.getVideoTracks()[0];

                Object.keys(state.peers).forEach(function (key) {
                    var peer = state.peers[key];
                    var sender = peer.videoSender || peer.pc.getSenders().find(function (s) { return !s.track || s.track.kind === 'video'; });
                    if (sender) sender.replaceTrack(screenTrack).catch(function () {});
                });

                state.isScreenSharing = true;
                attachTileStream(myUserId, stream);
                setTileVideoVisible(myUserId, true);
                updateGridLayout();
                screenSharingBadge.classList.remove('hidden');
                screenSharingBadge.classList.add('flex');
                setShareBtnUi(true);
                if (state.id) axios.post('/api/v1/calls/' + state.id + '/screen-share', { state: 'started' }).catch(function () {});

                // The browser's own "Stop sharing" bar ends the track
                // directly - must be treated the same as tapping Share
                // again.
                screenTrack.addEventListener('ended', stopScreenShare);
            }).catch(function (error) {
                // A real error rather than a swallowed one: the old code
                // discarded every getDisplayMedia rejection silently, so a
                // genuine failure looked identical to a user simply
                // cancelling the picker.
                var name = error && error.name;
                if (name === 'NotAllowedError' || name === 'AbortError') {
                    return; // The user dismissed the picker - not an error.
                }
                showCallError(name === 'NotFoundError'
                    ? 'No screen or window was available to share.'
                    : 'Screen sharing could not be started on this device.');
            });
        }

        function stopScreenShare() {
            if (!state || !state.isScreenSharing) {
                return;
            }

            if (state.screenStream) {
                state.screenStream.getTracks().forEach(function (t) { t.stop(); });
                state.screenStream = null;
            }

            // A voice call never had a camera track to fall back to -
            // replaceTrack(null) just stops sending on that transceiver
            // without a second renegotiation.
            var cameraTrack = state.type === 'video' && state.localStream ? state.localStream.getVideoTracks()[0] : null;
            Object.keys(state.peers).forEach(function (key) {
                var peer = state.peers[key];
                if (peer.videoSender) peer.videoSender.replaceTrack(cameraTrack || null).catch(function () {});
            });

            state.isScreenSharing = false;
            attachTileStream(myUserId, state.localStream);
            setTileVideoVisible(myUserId, !!cameraTrack);
            updateGridLayout();
            screenSharingBadge.classList.add('hidden');
            screenSharingBadge.classList.remove('flex');
            setShareBtnUi(false);
            if (state.id) axios.post('/api/v1/calls/' + state.id + '/screen-share', { state: 'stopped' }).catch(function () {});
        }

        // ---- Camera switch (front/back) -------------------------------

        var currentFacingMode = 'user';

        // Only worth offering at all on a device that genuinely has a
        // second camera - hidden entirely otherwise (#28: hide unusable
        // controls rather than show one that does nothing). Checked
        // once per call, since device labels/count don't change mid-call.
        function updateFlipCameraAvailability() {
            if (!state || state.type !== 'video' || !navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
                moreFlipCameraBtn.classList.add('hidden');
                return;
            }
            navigator.mediaDevices.enumerateDevices().then(function (devices) {
                var cameraCount = devices.filter(function (d) { return d.kind === 'videoinput'; }).length;
                moreFlipCameraBtn.classList.toggle('hidden', cameraCount < 2);
            }).catch(function () {
                moreFlipCameraBtn.classList.add('hidden');
            });
        }

        /**
         * Same replaceTrack() pattern as screen share above - every peer
         * connection's video sender is pre-provisioned, so switching
         * cameras never needs a second SDP negotiation and nobody sees
         * so much as a reconnect blip.
         */
        function flipCamera() {
            if (!state || !state.joined || state.type !== 'video' || state.isScreenSharing || !state.localStream) {
                return;
            }

            stopBlur();
            var nextFacing = currentFacingMode === 'user' ? 'environment' : 'user';

            navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: nextFacing } }, audio: false }).then(function (stream) {
                var newTrack = stream.getVideoTracks()[0];
                var oldTrack = state.localStream.getVideoTracks()[0];

                Object.keys(state.peers).forEach(function (key) {
                    var peer = state.peers[key];
                    if (peer.videoSender) peer.videoSender.replaceTrack(newTrack).catch(function () {});
                });

                if (oldTrack) {
                    state.localStream.removeTrack(oldTrack);
                    oldTrack.stop();
                }
                state.localStream.addTrack(newTrack);
                currentFacingMode = nextFacing;
            }).catch(function () {
                showCallError('Could not switch the camera.');
            });
        }

        moreFlipCameraBtn.addEventListener('click', function () {
            closeMoreMenu();
            flipCamera();
        });

        shareBtn.addEventListener('click', function () {
            closeMoreMenu();
            if (!state) return;
            if (state.isScreenSharing) {
                stopScreenShare();
            } else {
                startScreenShare();
            }
        });

        // ---- Decline with a quick message ---------------------------------
        var quickSheet = document.getElementById('call-quick-sheet');
        function sendChatText(conversationId, userId, text) {
            // same as a normal chat message: encrypted with the conversation key when the chat has one
            function post(conv) {
                var plain = function () { return axios.post('/api/v1/messages', { conversation_id: conv, message_type: 'text', body: text }); };
                if (!window.MeetE2E) return plain();
                return axios.get('/api/v1/conversations/' + conv + '/encryption').then(function (r) {
                    var st = r.data.data;
                    if (!st.eligible || !st.current_key_version) return null;
                    return MeetE2E.getConversationKey(conv, st.current_key_version).then(function (key) { return key ? { key: key, version: st.current_key_version } : null; });
                }, function () { return null; }).then(function (k) {
                    if (!k) return plain();
                    return MeetE2E.encryptText(text, k.key).then(function (enc) {
                        return axios.post('/api/v1/messages', { conversation_id: conv, message_type: 'text', body: enc.body, encryption_mode: 'e2e', iv: enc.iv, key_version: k.version });
                    });
                });
            }
            if (conversationId) return post(conversationId);
            return axios.post('/api/v1/conversations', { participant_id: userId }).then(function (r) { return post(r.data.data.id); });
        }
        function declineWithMessage(text) {
            text = (text || '').trim();
            if (!state || !text) return;
            var conv = state.conversationId, user = state.otherUserId;
            quickSheet.classList.add('hidden'); quickSheet.classList.remove('flex');
            axios.post('/api/v1/calls/' + state.id + '/reject').catch(function () {}).then(teardown);
            sendChatText(conv, user, text).catch(function (e) { console.error('Quick message not sent', e); });
        }
        document.getElementById('call-quick-msg-btn').addEventListener('click', function () {
            quickSheet.classList.remove('hidden'); quickSheet.classList.add('flex');
        });
        document.getElementById('call-quick-cancel').addEventListener('click', function () {
            quickSheet.classList.add('hidden'); quickSheet.classList.remove('flex');
        });
        Array.prototype.forEach.call(document.querySelectorAll('.call-quick-choice'), function (b) {
            b.addEventListener('click', function () { declineWithMessage(b.textContent); });
        });
        document.getElementById('call-quick-form').addEventListener('submit', function (e) {
            e.preventDefault();
            declineWithMessage(document.getElementById('call-quick-input').value);
        });

        // ---- Call ended screen -------------------------------------------
        var endedTimer = null, endedInfoNow = null;
        function showEnded(info) {
            endedInfoNow = info;
            var m = Math.floor(info.seconds / 60), sec = info.seconds % 60;
            document.getElementById('call-ended-name').textContent = info.name || 'Call';
            document.getElementById('call-ended-info').textContent = 'Call ended - ' + (m ? m + ' min ' : '') + sec + ' s';
            setAvatar(document.getElementById('call-ended-avatar'), info.name, info.photo);
            showPanel('ended');
            endedTimer = setTimeout(closeEnded, 4000); // short: it covers the page it was called from
        }
        function closeEnded() {
            clearTimeout(endedTimer);
            if (!state) hideOverlay();
            // the call was minimised over another page: go there now, the call no longer needs this page
            if (frameUrlAfterCall) { var u = frameUrlAfterCall; frameUrlAfterCall = null; if (u !== window.location.href) window.location.href = u; }
        }
        document.getElementById('call-ended-close').addEventListener('click', closeEnded);
        document.getElementById('call-ended-msg').addEventListener('click', function () {
            var i = endedInfoNow; closeEnded();
            if (i && i.userId) window.location.href = '/chat/start/' + i.userId;
        });
        document.getElementById('call-ended-again').addEventListener('click', function () {
            var i = endedInfoNow; clearTimeout(endedTimer);
            if (i && i.userId) { hideOverlay(); startCall(i.userId, i.type || 'audio', i.name, i.conversationId); }
        });

        // ---- Minimise: keep the call, use the rest of the app ---------------
        // Every Meet page is a full page load, which would end the call. So the call page stays as it is and the app opens
        // in a full-size frame under a slim call bar; links inside the frame only change the frame.
        var miniBar = document.getElementById('call-mini');
        var miniTimer = document.getElementById('call-mini-timer');
        var frameWrap = document.getElementById('call-app-frame-wrap');
        var appFrame = document.getElementById('call-app-frame');
        var minimised = false, frameUrlAfterCall = null;
        function minimise() {
            if (!state || !state.startedAt || IN_CALL_FRAME) return;
            minimised = true;
            document.getElementById('call-mini-name').textContent = state.otherName || 'Call';
            setAvatar(document.getElementById('call-mini-avatar'), state.otherName, state.otherPhoto);
            if (!appFrame.getAttribute('src')) appFrame.setAttribute('src', window.location.pathname === '/' ? '/dashboard' : window.location.href);
            overlay.classList.add('hidden'); overlay.classList.remove('flex'); // hidden only: the call's audio and video keep playing
            frameWrap.classList.remove('hidden');
            miniBar.classList.remove('hidden'); miniBar.classList.add('flex');
            document.body.style.overflow = 'hidden';
        }
        function restoreCall() {
            if (!state) return;
            minimised = false;
            miniBar.classList.add('hidden'); miniBar.classList.remove('flex');
            frameWrap.classList.add('hidden');
            overlay.classList.remove('hidden'); overlay.classList.add('flex');
            document.body.style.overflow = '';
        }
        function leaveMinimised() {
            if (!minimised && !appFrame.getAttribute('src')) return;
            try { frameUrlAfterCall = appFrame.getAttribute('src') ? appFrame.contentWindow.location.href : null; } catch (e) { frameUrlAfterCall = null; }
            minimised = false;
            miniBar.classList.add('hidden'); miniBar.classList.remove('flex');
            frameWrap.classList.add('hidden');
            appFrame.removeAttribute('src');
            document.body.style.overflow = '';
        }
        document.getElementById('call-minimise-btn').addEventListener('click', minimise);
        document.getElementById('call-mini-open').addEventListener('click', restoreCall);
        document.getElementById('call-mini-back').addEventListener('click', restoreCall);
        document.getElementById('call-mini-end').addEventListener('click', function () { document.getElementById('call-end-btn').click(); });
        document.getElementById('call-mini-mute').addEventListener('click', function () {
            document.getElementById('call-mute-btn').click();
            this.classList.toggle('bg-white', document.getElementById('call-mute-btn').classList.contains('call-control-btn--active'));
            this.classList.toggle('text-gray-900', document.getElementById('call-mute-btn').classList.contains('call-control-btn--active'));
        });

        // ---- Picture in picture (video calls): the other person's video floats outside the app ----
        var pipBtn = document.getElementById('call-pip-btn');
        function remoteVideoEl() {
            var v = grid.querySelector('.call-tile[data-tile-remote="1"] video');
            return v && v.srcObject && v.srcObject.getVideoTracks().length ? v : null;
        }
        setInterval(function () {
            var ok = !!(document.pictureInPictureEnabled && state && state.startedAt && remoteVideoEl());
            pipBtn.classList.toggle('hidden', !ok);
        }, 1500);
        pipBtn.addEventListener('click', function () {
            var v = remoteVideoEl();
            if (!v) return;
            (document.pictureInPictureElement === v ? document.exitPictureInPicture() : v.requestPictureInPicture()).catch(function () {});
        });

        @include('components.layouts.call-extras')

        window.MeetCalls = IN_CALL_FRAME && window.parent.MeetCalls
            // a call started from a page inside the minimised-call frame belongs to the outer page (it holds the call)
            ? { start: function () { return window.parent.MeetCalls.start.apply(null, arguments); } }
            : { start: startCall };

        startIncomingPoll();
        checkForIncomingCall();

        // A notification action tapped with no page open yet can't place
        // or answer a call directly (see public/service-worker.js's
        // notificationclick handler) - it opens/focuses a client window
        // with query params instead, picked up here the moment a real
        // page has loaded.
        var launchParams = new URLSearchParams(window.location.search);

        // "Call back" on a missed-call notification.
        if (launchParams.has('callback_user')) {
            var callbackUserId = parseInt(launchParams.get('callback_user'), 10);
            var callbackType = launchParams.get('callback_type') === 'video' ? 'video' : 'voice';
            var callbackName = launchParams.get('callback_name') || 'Unknown';

            if (callbackUserId) {
                startCall(callbackUserId, callbackType, callbackName, null);
            }
        }

        // "Accept" on an INCOMING-call notification. The service worker
        // has already told the server the call is accepted (so the caller
        // stops ringing immediately even if this page takes a moment to
        // load); all that's left here is to actually join the media
        // session, which only a page can do.
        if (launchParams.get('answer_call')) {
            var answeredCallId = parseInt(launchParams.get('answer_call'), 10);
            if (answeredCallId) {
                axios.get('/api/v1/calls/' + answeredCallId).then(function (response) {
                    var call = response.data.data;
                    if (state || !call || ['ringing', 'accepted'].indexOf(call.call_status) === -1) {
                        return;
                    }
                    var others = (call.participants || []).filter(function (p) { return !p.is_me; });
                    state = {
                        id: call.id,
                        type: call.call_type,
                        role: call.is_caller ? 'caller' : 'receiver',
                        otherName: others.length === 1 ? others[0].full_name : (call.caller && call.caller.full_name),
                        peers: {},
                        cursor: { signal: 0, ice: 0 },
                    };
                    clearInterval(timers.incomingPoll);
                    timers.incomingPoll = null;
                    // Still ringing (the worker's accept POST didn't get
                    // through) - fall back to the normal Accept flow.
                    if (call.call_status === 'ringing') {
                        acceptIncomingCall();
                    } else {
                        becomeActive();
                    }
                }).catch(function () {});
            }
        }

        ['callback_user', 'callback_type', 'callback_name', 'answer_call'].forEach(function (param) {
            launchParams.delete(param);
        });
        var cleanedQuery = launchParams.toString();
        var cleanedUrl = window.location.pathname + (cleanedQuery ? '?' + cleanedQuery : '') + window.location.hash;
        window.history.replaceState(null, '', cleanedUrl);
    });
    </script>

    <script>
    // Every page that can send/render E2E messages awaits this promise
    // instead of assuming a synchronous global - it resolves to true
    // once e2e-crypto.js (a Vite type="module" asset, deferred until
    // after the document is parsed - this classic inline script can
    // genuinely run before it) is actually available, or false if the
    // browser lacks WebCrypto/IndexedDB, or the module never loads
    // (gives up after 5s rather than polling forever). No per-user
    // identity/unlock step exists any more - key access is checked live
    // per request (conversation membership), not by anything the
    // client holds.
    window.MeetE2EReady = (function () {
        if (!window.crypto || !window.crypto.subtle || !window.indexedDB) {
            return Promise.resolve(false);
        }

        function waitForMeetE2E(deadline) {
            if (window.MeetE2E) {
                return Promise.resolve(true);
            }
            if (Date.now() > deadline) {
                return Promise.resolve(false);
            }
            return new Promise(function (resolve) { setTimeout(resolve, 10); }).then(function () {
                return waitForMeetE2E(deadline);
            });
        }

        return waitForMeetE2E(Date.now() + 5000);
    })();
    </script>

    <script>
    // Offline queue draining lives here (not per-page) since it must
    // keep running regardless of which page the user happens to be on
    // when connectivity returns - a page-specific script would only
    // drain the queue if that exact page were still open. Individual
    // pages (chat/show.blade.php, etc.) register their own handlers on
    // window.MeetOfflineHandlers for the action types they know how to
    // replay; this only orchestrates *when* to drain, never *how*.
    window.MeetOfflineHandlers = window.MeetOfflineHandlers || {};

    (function () {
        if (!window.indexedDB) return;

        // offline.js is a Vite type="module" asset (deferred by spec) -
        // this classic script can genuinely run before it, exactly the
        // same hazard the E2E unlock script above already has to work
        // around. Registering the online/offline listeners has to wait
        // for the real module to exist, or they're silently never
        // attached at all.
        function waitForMeetOffline(deadline) {
            if (window.MeetOffline) return Promise.resolve(true);
            if (Date.now() > deadline) return Promise.resolve(false);
            return new Promise(function (resolve) { setTimeout(resolve, 10); }).then(function () {
                return waitForMeetOffline(deadline);
            });
        }

        waitForMeetOffline(Date.now() + 5000).then(function (available) {
            if (!available) return;

            var banner = document.getElementById('offline-banner');
            var queueCountEl = document.getElementById('offline-queue-count');

            function updateQueueBadge() {
                MeetOffline.queueLength().then(function (n) {
                    queueCountEl.textContent = n > 0 ? '(' + n + ' queued to send)' : '';
                }).catch(function () {});
            }

            function setOfflineUi(isOffline) {
                banner.classList.toggle('hidden', !isOffline);
                banner.classList.toggle('flex', isOffline);
            }

            function drainQueue() {
                MeetOffline.processQueue(window.MeetOfflineHandlers).then(updateQueueBadge);
            }

            setOfflineUi(!navigator.onLine);
            updateQueueBadge();

            window.addEventListener('offline', function () { setOfflineUi(true); });
            window.addEventListener('online', function () {
                setOfflineUi(false);
                drainQueue();
            });

            // A tab that was already open when connectivity dropped and
            // came back needs no extra help (the online listener above
            // covers it) - this only catches the queue being non-empty
            // from a *previous* session that never got a chance to
            // drain it (e.g. the tab was closed while still offline).
            if (navigator.onLine) drainQueue();
        });
    })();
    </script>
{{-- Meet help chat: answers only from our own guides and prices (served by Insights) --}}
<script src="{{ rtrim((string) config('services.insights.url'), '/') }}/zx/helpbot.js" data-product="meet" data-side="left" data-bottom="90" defer></script>
</body>
</html>
