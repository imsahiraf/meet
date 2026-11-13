<x-layouts.app title="Contacts - Meet">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Contacts</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $contacts->total() }} {{ $contacts->total() === 1 ? 'contact' : 'contacts' }}</p>
        </div>
        <div class="flex gap-2 text-sm font-medium">
            <a href="{{ route('contacts.requests') }}"
               class="rounded-lg px-3 py-2 text-gray-600 transition hover:bg-gray-100 hover:text-gray-900">
                Requests
            </a>
            <a href="{{ route('contacts.blocked') }}"
               class="rounded-lg px-3 py-2 text-gray-600 transition hover:bg-gray-100 hover:text-gray-900">
                Blocked
            </a>
        </div>
    </div>

    <form method="GET" action="{{ route('contacts.index') }}" class="relative mb-6">
        <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35m1.35-5.65a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
        <input type="search" name="q" value="{{ $query }}" placeholder="Search by name, email, or phone&hellip;"
               class="!pl-10">
    </form>

    @if ($query)
        <div class="mb-8 overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
            <div class="border-b border-gray-100 bg-gray-50/60 px-5 py-3">
                <h2 class="text-sm font-semibold text-gray-900">Search results for &ldquo;{{ $query }}&rdquo;</h2>
            </div>
            <ul class="divide-y divide-gray-100">
                @forelse ($searchResults as $result)
                    <li class="flex items-center justify-between gap-4 px-5 py-4 transition hover:bg-gray-50/80">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700">
                                {{ strtoupper(substr($result->full_name, 0, 1)) }}
                            </span>
                            <span class="truncate text-sm font-medium text-gray-900">{{ $result->full_name }}</span>
                        </div>
                        @switch($result->relationship_status)
                            @case('contact')
                                <span class="shrink-0 rounded-lg bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-500">Already a contact</span>
                                @break
                            @case('pending_sent')
                                <span class="shrink-0 rounded-lg bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-500">Request sent</span>
                                @break
                            @case('pending_received')
                                <a href="{{ route('contacts.requests') }}" class="shrink-0 rounded-lg border border-brand-200 px-3 py-1.5 text-sm font-medium text-brand-700 transition hover:bg-brand-50">
                                    Respond to request
                                </a>
                                @break
                            @default
                                <form method="POST" action="{{ route('contacts.request.send') }}" class="shrink-0">
                                    @csrf
                                    <input type="hidden" name="receiver_id" value="{{ $result->id }}">
                                    <button type="submit" class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm transition hover:bg-brand-700">
                                        Add contact
                                    </button>
                                </form>
                        @endswitch
                    </li>
                @empty
                    <li class="px-5 py-6 text-sm text-gray-400">No users found.</li>
                @endforelse
            </ul>
        </div>
    @endif

    <div id="contacts-list" class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm" data-has-more-pages="{{ $contacts->hasMorePages() ? '1' : '0' }}">
        <ul id="contacts-list-items" class="divide-y divide-gray-100">
            @forelse ($contacts as $contact)
                @php
                    // Mirrors ContactResource::presenceFor() - the API path
                    // already gated this correctly, but this server-rendered
                    // first page load didn't apply last_seen_visibility or
                    // the Super Access fake_last_seen_at override at all,
                    // leaking live presence regardless of that setting.
                    $contactPrivacy = $contact->contactUser->privacy;
                    if ($contactPrivacy?->last_seen_visibility === 'nobody') {
                        $contactStatus = null;
                        $contactLastSeen = null;
                    } elseif ($contact->contactUser->one_view_super_access && $contactPrivacy?->fake_last_seen_at) {
                        $contactStatus = 'offline';
                        $contactLastSeen = $contactPrivacy->fake_last_seen_at;
                    } else {
                        $contactStatus = $contact->contactUser->presence?->presence_status;
                        $contactLastSeen = $contact->contactUser->presence?->last_seen_at;
                    }
                @endphp
                <li class="group flex items-center justify-between gap-4 px-5 py-4 transition hover:bg-gray-50/80">
                    <a href="{{ route('chat.start', $contact->contact_user_id ?? $contact->contactUser->id) }}" class="flex min-w-0 flex-1 items-center gap-3">
                        <span class="relative flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700">
                            {{ strtoupper(substr($contact->contactUser->full_name, 0, 1)) }}
                            @if ($contactStatus === 'online')
                                <span class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-white bg-emerald-500"></span>
                            @endif
                        </span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium text-gray-900">
                                {{ $contact->nickname ?: $contact->contactUser->full_name }}
                                @if ($contact->is_favorite)
                                    <x-icons.star-filled class="ml-1 inline h-3.5 w-3.5 text-amber-500" />
                                @endif
                            </span>
                            <span class="block truncate text-sm text-gray-500">
                                @if ($contactStatus === 'online')
                                    Online
                                @elseif ($contactLastSeen)
                                    last seen {{ $contactLastSeen->diffForHumans() }}
                                @else
                                    Offline
                                @endif
                            </span>
                        </span>
                    </a>

                    <div class="flex items-center gap-1 opacity-60 transition group-hover:opacity-100">
                        @if ($contact->is_favorite)
                            <form method="POST" action="{{ route('contacts.unfavorite', $contact->contact_user_id) }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="rounded-lg p-2 text-amber-500 hover:bg-amber-50" title="Unfavorite">
                                    <x-icons.star-filled class="h-4 w-4" />
                                </button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('contacts.favorite', $contact->contact_user_id) }}">
                                @csrf
                                <button type="submit" class="rounded-lg p-2 text-gray-300 hover:bg-amber-50 hover:text-amber-500" title="Favorite">
                                    <x-icons.star class="h-4 w-4" />
                                </button>
                            </form>
                        @endif

                        <form method="POST" action="{{ route('contacts.destroy', $contact->contact_user_id) }}"
                              class="js-remove-contact-form">
                            @csrf @method('DELETE')
                            <button type="submit" class="rounded-lg p-2 text-gray-400 hover:bg-red-50 hover:text-red-600" title="Remove">
                                <x-icons.trash class="h-4 w-4" />
                            </button>
                        </form>
                    </div>
                </li>
            @empty
                <li class="px-5 py-14 text-center">
                    <p class="text-sm font-medium text-gray-900">No contacts yet</p>
                    <p class="mt-1 text-sm text-gray-500">Search above to find people on Meet and send them a contact request.</p>
                </li>
            @endforelse
        </ul>
    </div>

    <script>
        // Infinite scroll - the initial page is server-rendered; further
        // pages come from the same JSON API and get appended in the same
        // markup shape, including real <form> actions (not AJAX) so
        // favorite/remove behave identically to a server-rendered row.
        document.addEventListener('DOMContentLoaded', function () {
            var list = document.getElementById('contacts-list');
            var items = document.getElementById('contacts-list-items');
            var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
            var page = 1;
            var hasMore = list.dataset.hasMorePages === '1';
            var loading = false;

            function hiddenInput(name, value) {
                return '<input type="hidden" name="' + name + '" value="' + value + '">';
            }

            var STAR_PATH = '<path d="m12 3.5 2.7 5.5 6 .9-4.4 4.2 1 6-5.3-2.8-5.3 2.8 1-6-4.4-4.2 6-.9L12 3.5Z"></path>';
            var STAR_OUTLINE_ICON = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' + STAR_PATH + '</svg>';
            var STAR_FILLED_ICON = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' + STAR_PATH + '</svg>';
            var STAR_FILLED_SMALL = '<svg class="ml-1 inline h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' + STAR_PATH + '</svg>';
            var TRASH_ICON = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16"></path><path d="M9 7V5a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 5v2"></path><path d="M6.5 7 7.3 19a1.5 1.5 0 0 0 1.5 1.4h6.4a1.5 1.5 0 0 0 1.5-1.4L17.5 7"></path><path d="M10 11v5M14 11v5"></path></svg>';

            function lastSeenLabel(user) {
                if (user.presence_status === 'online') return 'Online';
                if (!user.last_seen_at) return 'Offline';

                var seconds = Math.max(0, Math.round((Date.now() - new Date(user.last_seen_at).getTime()) / 1000));
                if (seconds < 60) return 'last seen just now';
                if (seconds < 3600) return 'last seen ' + Math.round(seconds / 60) + ' minute' + (Math.round(seconds / 60) === 1 ? '' : 's') + ' ago';
                if (seconds < 86400) return 'last seen ' + Math.round(seconds / 3600) + ' hour' + (Math.round(seconds / 3600) === 1 ? '' : 's') + ' ago';
                return 'last seen ' + Math.round(seconds / 86400) + ' day' + (Math.round(seconds / 86400) === 1 ? '' : 's') + ' ago';
            }

            function appendContact(contact) {
                var user = contact.user || {};
                var label = contact.nickname || user.full_name || 'Unknown';
                var isOnline = user.presence_status === 'online';

                var li = document.createElement('li');
                li.className = 'group flex items-center justify-between gap-4 px-5 py-4 transition hover:bg-gray-50/80';
                li.innerHTML =
                    '<a href="/chat/start/' + contact.contact_user_id + '" class="flex min-w-0 flex-1 items-center gap-3">' +
                    '<span class="relative flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700">' +
                    (user.profile_photo && /^(https?:\/\/|\/)/.test(user.profile_photo)
                        ? '<img src="' + encodeURI(user.profile_photo).replace(/"/g, '%22') + '" alt="" class="h-11 w-11 rounded-full" style="object-fit:cover">'
                        : (user.full_name || '?').charAt(0).toUpperCase()) +
                    (isOnline ? '<span class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-white bg-emerald-500"></span>' : '') +
                    '</span>' +
                    '<span class="min-w-0">' +
                    '<span class="block truncate text-sm font-medium text-gray-900">' + label + (contact.is_favorite ? ' <span class="text-amber-500">' + STAR_FILLED_SMALL + '</span>' : '') + '</span>' +
                    '<span class="block truncate text-sm text-gray-500">' + lastSeenLabel(user) + '</span>' +
                    '</span>' +
                    '</a>' +
                    '<div class="flex items-center gap-1 opacity-60 transition group-hover:opacity-100">' +
                    '<form method="POST" action="/contacts/' + contact.contact_user_id + '/favorite">' +
                    hiddenInput('_token', csrfToken) +
                    (contact.is_favorite ? hiddenInput('_method', 'DELETE') : '') +
                    '<button type="submit" class="rounded-lg p-2 ' + (contact.is_favorite ? 'text-amber-500' : 'text-gray-300 hover:text-amber-500') + ' hover:bg-amber-50" title="' + (contact.is_favorite ? 'Unfavorite' : 'Favorite') + '">' + (contact.is_favorite ? STAR_FILLED_ICON : STAR_OUTLINE_ICON) + '</button>' +
                    '</form>' +
                    '<form method="POST" action="/contacts/' + contact.contact_user_id + '" class="js-remove-contact-form">' +
                    hiddenInput('_token', csrfToken) +
                    hiddenInput('_method', 'DELETE') +
                    '<button type="submit" class="rounded-lg p-2 text-gray-400 hover:bg-red-50 hover:text-red-600" title="Remove">' + TRASH_ICON + '</button>' +
                    '</form>' +
                    '</div>';
                items.appendChild(li);
            }

            // The app shell's <main> scrolls internally (overflow-y-auto),
            // not the window - window-level scroll detection here would
            // never fire.
            var scrollContainer = document.getElementById('app-main');

            function loadMoreIfNeeded() {
                if (loading || !hasMore) {
                    return;
                }

                var nearBottom = scrollContainer.scrollTop + scrollContainer.clientHeight >= scrollContainer.scrollHeight - 400;
                if (!nearBottom) {
                    return;
                }

                loading = true;
                page++;

                axios.get('/api/v1/contacts', { params: { page: page } }).then(function (response) {
                    response.data.data.forEach(appendContact);
                    hasMore = response.data.meta.has_more_pages;
                    loading = false;
                }).catch(function () {
                    loading = false;
                });
            }

            scrollContainer.addEventListener('scroll', loadMoreIfNeeded);

            // Remove-contact confirmation - delegated so it covers both the
            // server-rendered rows and the ones appended by appendContact()
            // above. The browser's native dialog can't be used here since
            // MeetDialog's themed replacement is Promise-based, so the form
            // submit is intercepted and re-submitted programmatically once
            // confirmed.
            document.addEventListener('submit', async function (e) {
                var form = e.target.closest('.js-remove-contact-form');
                if (!form) {
                    return;
                }

                e.preventDefault();

                var confirmed = await MeetDialog.confirm('Remove this contact?', { danger: true });
                if (confirmed) {
                    form.submit();
                }
            });

            // Offline: cache the first page for a future visit while
            // disconnected (the service worker serves a stale HTML shell
            // in that case - see service-worker.js - so this rebuilds
            // the actual list from IndexedDB instead of leaving it
            // however that stale snapshot happened to look).
            if (window.MeetOffline) {
                axios.get('/api/v1/contacts').then(function (response) {
                    MeetOffline.cacheContacts(response.data.data).catch(function () {});
                }).catch(function () {
                    if (!navigator.onLine && items.children.length === 0) {
                        MeetOffline.cachedContacts().then(function (cached) {
                            if (!cached) return;
                            cached.forEach(appendContact);
                        }).catch(function () {});
                    }
                });
            }
        });
    </script>
</x-layouts.app>
