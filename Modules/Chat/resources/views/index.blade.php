<x-layouts.app :title="($archivedView ? 'Archived chats' : 'Chat') . ' - Meet'">
    <div id="chat-list-header" class="mb-6 flex flex-wrap items-center justify-between gap-y-2 gap-x-4">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">{{ $archivedView ? 'Archived chats' : 'Chat' }}</h1>
            @if ($archivedView)
                <a href="{{ route('chat.index') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700">&larr; Back to chats</a>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            @unless ($archivedView)
                <a href="{{ route('contacts.requests') }}" id="contact-requests-icon-link" class="relative hidden rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-700" title="Contact requests">
                    <x-icons.contacts class="h-5 w-5" />
                    <span id="contact-requests-icon-badge" class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold text-white"></span>
                </a>
                <a href="{{ route('chat.starred') }}" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-700" title="Starred messages">
                    <x-icons.star class="h-5 w-5" />
                </a>
                <a href="{{ route('chat.archived') }}" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-700" title="Archived chats">
                    <x-icons.archive class="h-5 w-5" />
                </a>
                <a href="{{ route('broadcast-lists.index') }}" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-700" title="Broadcast lists">
                    <x-icons.megaphone class="h-5 w-5" />
                </a>
            @endunless
            <button type="button" id="chat-select-btn" class="rounded-lg px-3 py-2 text-sm font-medium text-gray-500 hover:bg-gray-100 hover:text-gray-700">
                Select
            </button>
            {{-- Was a plain link straight to Contacts with no way to
                 reach "New group" at all from here (groups.create only
                 ever linked from the Groups tab's own empty state) -
                 now a small dropdown offering both, matching how
                 WhatsApp/Insta/Teams all surface "new chat" vs "new
                 group" from the same "+" entry point. --}}
            <div class="relative" id="chat-new-menu-wrap">
                <button type="button" id="chat-new-btn" class="flex items-center gap-1 rounded-lg p-2 text-brand-600 hover:bg-brand-50" title="New chat or group">
                    <x-icons.plus class="h-5 w-5" />
                </button>
                <div id="chat-new-menu" class="absolute right-0 top-full z-10 mt-1 hidden w-44 rounded-xl border border-gray-100 bg-white py-1 shadow-lg">
                    <a href="{{ route('contacts.index') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">New chat</a>
                    <a href="{{ route('groups.create') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">New group</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var newBtn = document.getElementById('chat-new-btn');
            var newMenu = document.getElementById('chat-new-menu');
            if (!newBtn || !newMenu) return;

            newBtn.addEventListener('click', function (event) {
                event.stopPropagation();
                newMenu.classList.toggle('hidden');
            });
            document.addEventListener('click', function (event) {
                if (!newMenu.contains(event.target) && event.target !== newBtn) {
                    newMenu.classList.add('hidden');
                }
            });
        });
    </script>

    {{-- WhatsApp-style multi-select bulk action bar - swapped in for the
         normal header while select mode is active (toggled via JS below). --}}
    <div id="chat-bulk-bar" class="mb-6 hidden items-center justify-between gap-3 rounded-2xl border border-brand-100 bg-brand-50/60 px-4 py-3">
        <div class="flex items-center gap-3">
            <button type="button" id="chat-bulk-cancel-btn" class="rounded-lg p-1.5 text-gray-500 hover:bg-white/60 hover:text-gray-700" title="Cancel">&times;</button>
            <span class="text-sm font-semibold text-brand-800"><span id="chat-bulk-count">0</span> selected</span>
        </div>
        <div class="flex items-center gap-1">
            <button type="button" id="chat-bulk-archive-btn" disabled class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-600 hover:bg-white/60 disabled:cursor-not-allowed disabled:opacity-40">
                {{ $archivedView ? 'Unarchive' : 'Archive' }}
            </button>
            <button type="button" id="chat-bulk-mute-btn" disabled class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-600 hover:bg-white/60 disabled:cursor-not-allowed disabled:opacity-40">
                Mute
            </button>
            <button type="button" id="chat-bulk-delete-btn" disabled class="rounded-lg px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-white/60 disabled:cursor-not-allowed disabled:opacity-40">
                Delete
            </button>
        </div>
    </div>

    {{-- Pending incoming contact requests, inline (WhatsApp shows these as
         part of the chat list itself rather than requiring a separate
         page visit) - Modules/Contacts/resources/views/requests.blade.php
         still exists as a secondary deep-link with Sent requests too. --}}
    <div id="contact-requests-banner" class="mb-4 hidden overflow-hidden rounded-2xl border border-brand-100 bg-brand-50/60" data-current-user-uuid="{{ auth()->user()->uuid }}">
        <div class="flex items-center justify-between px-5 py-3">
            <p class="text-sm font-semibold text-brand-800"><span id="contact-requests-label"></span></p>
            <a href="{{ route('contacts.requests') }}" class="text-xs font-medium text-brand-700 hover:underline">View all &rarr;</a>
        </div>
        <ul id="contact-requests-list" class="divide-y divide-brand-100/80 border-t border-brand-100/80 bg-white"></ul>
    </div>

    @unless ($archivedView)
        <div class="mb-4 flex gap-1 rounded-xl border border-gray-100 bg-white p-1 shadow-sm" role="tablist">
            <button type="button" data-conversation-filter="all" class="conversation-filter-tab flex-1 rounded-lg px-3 py-1.5 text-sm font-medium">All</button>
            <button type="button" data-conversation-filter="direct" class="conversation-filter-tab flex-1 rounded-lg px-3 py-1.5 text-sm font-medium">Chats</button>
            <button type="button" data-conversation-filter="group" class="conversation-filter-tab flex-1 rounded-lg px-3 py-1.5 text-sm font-medium">Groups</button>
        </div>
    @endunless

    <div id="conversation-list" class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm" data-archived-view="{{ $archivedView ? '1' : '0' }}" data-has-more-pages="{{ $conversations->hasMorePages() ? '1' : '0' }}">
        {{-- Distinct from the @empty block below (which covers having
             literally zero conversations at all) - this covers having
             conversations overall, but none matching the currently
             selected Chats/Groups tab, which previously just showed a
             blank white area with no explanation at all. --}}
        <p id="conversation-filter-empty" class="hidden px-5 py-10 text-center text-sm text-gray-400"></p>
        <ul id="conversation-list-items" class="divide-y divide-gray-100">
            @forelse ($conversations as $conversation)
                @php
                    $isGroup = $conversation->type === 'group';
                    $other = $isGroup ? null : $conversation->members->firstWhere('user_id', '!=', auth()->id())?->user;
                    $label = $isGroup ? $conversation->title : ($other?->full_name ?? 'Unknown');
                    $photo = $other?->photoFor(auth()->user());
                    $isMuted = in_array($conversation->id, $mutedConversationIds, true);
                @endphp
                {{-- WhatsApp-style swipe-to-reveal replaces what used to be a
                     persistent per-row Archive button (users reported
                     accidental archives just from it sitting there) - a
                     right-to-left swipe on .conversation-row-content (see
                     the script below, mirroring show.blade.php's own
                     swipe-to-reply touchstart/touchmove/touchend handling
                     on .message-bubble) slides the row aside to reveal
                     Archive/Delete underneath. "Select" mode's own bulk
                     actions further down are untouched. --}}
                <li data-conversation-uuid="{{ $conversation->uuid }}" data-conversation-id="{{ $conversation->id }}" data-conversation-type="{{ $isGroup ? 'group' : 'direct' }}" class="conversation-row relative overflow-hidden">
                    <div class="conversation-row-actions absolute inset-y-0 right-0 flex">
                        <button type="button" data-conversation-action="archive" data-conversation-id="{{ $conversation->id }}" class="flex w-20 shrink-0 items-center justify-center bg-gray-500 text-xs font-medium text-white">
                            {{ $archivedView ? 'Unarchive' : 'Archive' }}
                        </button>
                        <button type="button" data-conversation-action="delete" data-conversation-id="{{ $conversation->id }}" class="flex w-20 shrink-0 items-center justify-center bg-red-600 text-xs font-medium text-white">
                            Delete
                        </button>
                    </div>
                    <div class="conversation-row-content group relative flex touch-pan-y items-center gap-2 bg-white px-2 transition-transform duration-200 ease-out">
                        <input type="checkbox" class="conversation-select-checkbox ml-3 hidden h-4 w-4 shrink-0 rounded border-gray-300 text-brand-600 focus:ring-brand-500" data-conversation-id="{{ $conversation->id }}">
                        <a href="{{ route('chat.show', $conversation) }}" class="flex min-w-0 flex-1 items-center gap-3 px-3 py-4 hover:bg-gray-50">
                            @if ($photo)
                            <img src="{{ $photo }}" alt="" class="h-10 w-10 shrink-0 rounded-full" style="object-fit:cover">
                            @else
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $isGroup ? 'bg-emerald-100 text-emerald-700' : 'bg-brand-100 text-brand-700' }} text-sm font-semibold">
                                {{ strtoupper(substr($label ?? '?', 0, 1)) }}
                            </span>
                            @endif
                            <span class="min-w-0 flex-1">
                                {{-- Name + timestamp on one row, last-message
                                     preview + unread count on the row below -
                                     matching WhatsApp's own two-row layout,
                                     not squeezing the badge onto the name row
                                     where it used to float awkwardly with
                                     nothing to anchor it on the right. --}}
                                <span class="flex items-center justify-between gap-2">
                                    <span class="truncate text-sm font-medium text-gray-900">
                                        {{ $label }}
                                        @if ($isMuted)<span class="ml-1 text-gray-400" title="Muted">&#128263;</span>@endif
                                    </span>
                                    <span class="conversation-time shrink-0 text-xs text-gray-400" data-last-activity="{{ $conversation->last_activity_at?->toISOString() }}"></span>
                                </span>
                                <span class="flex items-center justify-between gap-2">
                                    {{-- The server can never decrypt an E2E last message - a safe
                                         placeholder renders here first, then JS decrypts it
                                         client-side (see the script below) the moment the page
                                         loads, same as it does for every row fetched via the API. --}}
                                    <span class="conversation-last-message min-w-0 flex-1 truncate text-sm text-gray-500"
                                          data-encryption-mode="{{ $conversation->lastMessage?->encryption_mode ?? 'none' }}"
                                          data-body="{{ $conversation->lastMessage?->encryption_mode === 'e2e' ? $conversation->lastMessage->body : '' }}"
                                          data-iv="{{ $conversation->lastMessage?->iv }}"
                                          data-key-version="{{ $conversation->lastMessage?->key_version }}">
                                        {{ $conversation->lastMessage?->encryption_mode === 'e2e' ? '🔒 Encrypted message' : ($conversation->lastMessage?->body ?? 'No messages yet') }}
                                    </span>
                                    <span class="conversation-unread shrink-0 hidden rounded-full bg-brand-600 px-2 py-0.5 text-xs font-semibold text-white"></span>
                                </span>
                            </span>
                        </a>
                    </div>
                </li>
            @empty
                <li class="px-5 py-10 text-center text-sm text-gray-400">
                    @if ($archivedView)
                        No archived chats.
                    @else
                        No conversations yet. <a href="{{ route('contacts.index') }}" class="font-medium text-brand-600 hover:text-brand-700">Find a contact</a> to start chatting.
                    @endif
                </li>
            @endforelse
        </ul>
    </div>

    <script>
        // FR-053 - conversation list polling (unread counts, last message).
        //
        // Wrapped in DOMContentLoaded because Vite's script tag is
        // type="module" (deferred by spec) - it hasn't run yet, and
        // window.axios doesn't exist yet, at the point the parser
        // reaches this plain inline script further down the page.
        document.addEventListener('DOMContentLoaded', function () {
            var pollMs = {{ (int) $pollingConfig['conversation_list_ms'] }};
            var archivedView = document.getElementById('conversation-list').dataset.archivedView === '1';
            var selectModeActive = false;

            function initials(name) {
                return (name || '?').trim().charAt(0).toUpperCase();
            }

            // Shared by every place a conversation row gets built client-side
            // (offline cache render + infinite-scroll append below) so the
            // swipe-to-reveal actions markup (see the swipe IIFE further
            // down) never drifts out of sync with the server-rendered row.
            function conversationRowActionsHtml(conversationId) {
                return '<div class="conversation-row-actions absolute inset-y-0 right-0 flex">' +
                    '<button type="button" data-conversation-action="archive" data-conversation-id="' + conversationId + '" class="flex w-20 shrink-0 items-center justify-center bg-gray-500 text-xs font-medium text-white">' +
                    (archivedView ? 'Unarchive' : 'Archive') +
                    '</button>' +
                    '<button type="button" data-conversation-action="delete" data-conversation-id="' + conversationId + '" class="flex w-20 shrink-0 items-center justify-center bg-red-600 text-xs font-medium text-white">Delete</button>' +
                    '</div>';
            }

            // WhatsApp-style: a bare time today, "Yesterday" the day
            // after, a short date beyond that - never a raw ISO string.
            function formatConversationTime(iso) {
                if (!iso) return '';
                var date = new Date(iso);
                var now = new Date();
                if (date.toDateString() === now.toDateString()) {
                    var h = date.getHours();
                    var m = date.getMinutes();
                    var ampm = h >= 12 ? 'PM' : 'AM';
                    h = h % 12 || 12;
                    return h + ':' + (m < 10 ? '0' : '') + m + ' ' + ampm;
                }
                var yesterday = new Date(now);
                yesterday.setDate(now.getDate() - 1);
                if (date.toDateString() === yesterday.toDateString()) {
                    return 'Yesterday';
                }
                return (date.getMonth() + 1) + '/' + date.getDate() + '/' + String(date.getFullYear()).slice(-2);
            }

            function renderConversationTime(el, iso) {
                el.textContent = formatConversationTime(iso);
            }

            // The server can never decrypt an E2E last message (it
            // doesn't hold the key) - every row shows a safe "🔒
            // Encrypted message" placeholder first, then this decrypts
            // it client-side and swaps in the real text, the same way
            // an open conversation's own thread already does per
            // message. Never touches a genuinely plaintext message.
            function renderLastMessagePreview(el, conversationId, lastMessage) {
                if (!lastMessage) {
                    el.textContent = 'No messages yet';
                    return;
                }
                if (lastMessage.encryption_mode !== 'e2e') {
                    el.textContent = lastMessage.body || 'Attachment';
                    return;
                }
                el.textContent = '🔒 Encrypted message';
                if (!window.MeetE2E) return;
                MeetE2E.getConversationKey(conversationId, lastMessage.key_version).then(function (key) {
                    if (!key) return;
                    return MeetE2E.decryptText(lastMessage.body, lastMessage.iv, key).then(function (plaintext) {
                        el.textContent = plaintext;
                    });
                }).catch(function () {});
            }

            // The initial page load is server-rendered Blade, not the
            // API - decrypt those rows' placeholders the same way,
            // using the raw ciphertext stashed in data-body (never
            // rendered as visible text server-side, only as an
            // attribute for this to read).
            document.querySelectorAll('#conversation-list-items > li[data-conversation-id]').forEach(function (li) {
                var timeEl = li.querySelector('.conversation-time');
                if (timeEl) renderConversationTime(timeEl, timeEl.dataset.lastActivity);

                var el = li.querySelector('.conversation-last-message');
                if (!el || el.dataset.encryptionMode !== 'e2e') return;
                renderLastMessagePreview(el, li.dataset.conversationId, {
                    encryption_mode: 'e2e',
                    body: el.dataset.body,
                    iv: el.dataset.iv,
                    key_version: el.dataset.keyVersion ? parseInt(el.dataset.keyVersion, 10) : null,
                });
            });

            function renderConversationRow(conversation) {
                var isGroup = conversation.type === 'group';
                var label = isGroup ? conversation.title : (conversation.other_user?.full_name || 'Unknown');

                var li = document.createElement('li');
                li.dataset.conversationUuid = conversation.uuid;
                li.dataset.conversationId = conversation.id;
                li.dataset.conversationType = isGroup ? 'group' : 'direct';
                li.className = 'conversation-row relative overflow-hidden';
                li.innerHTML =
                    conversationRowActionsHtml(conversation.id) +
                    '<div class="conversation-row-content group relative flex touch-pan-y items-center gap-2 bg-white px-2 transition-transform duration-200 ease-out">' +
                    '<input type="checkbox" class="conversation-select-checkbox ml-3 hidden h-4 w-4 shrink-0 rounded border-gray-300 text-brand-600 focus:ring-brand-500" data-conversation-id="' + conversation.id + '">' +
                    '<a href="/chat/' + conversation.id + '" class="flex min-w-0 flex-1 items-center gap-3 px-3 py-4 hover:bg-gray-50">' +
                    (!isGroup && conversation.other_user && conversation.other_user.profile_photo && /^(https?:\/\/|\/)/.test(conversation.other_user.profile_photo)
                        ? '<img src="' + encodeURI(conversation.other_user.profile_photo).replace(/"/g, '%22') + '" alt="" class="h-10 w-10 shrink-0 rounded-full" style="object-fit:cover">'
                        : '<span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full ' + (isGroup ? 'bg-emerald-100 text-emerald-700' : 'bg-brand-100 text-brand-700') + ' text-sm font-semibold">' +
                    initials(label) +
                    '</span>') +
                    '<span class="min-w-0 flex-1">' +
                    '<span class="flex items-center justify-between gap-2">' +
                    '<span class="truncate text-sm font-medium text-gray-900">' + (label || 'Unknown') + '</span>' +
                    '<span class="conversation-time shrink-0 text-xs text-gray-400"></span>' +
                    '</span>' +
                    '<span class="flex items-center justify-between gap-2">' +
                    '<span class="conversation-last-message min-w-0 flex-1 truncate text-sm text-gray-500"></span>' +
                    '<span class="conversation-unread shrink-0 ' + (conversation.unread_count > 0 ? '' : 'hidden ') + 'rounded-full bg-brand-600 px-2 py-0.5 text-xs font-semibold text-white">' + (conversation.unread_count || '') + '</span>' +
                    '</span>' +
                    '</span>' +
                    '</a>' +
                    '</div>';
                renderConversationTime(li.querySelector('.conversation-time'), conversation.last_activity_at);
                renderLastMessagePreview(li.querySelector('.conversation-last-message'), conversation.id, conversation.last_message);
                return li;
            }

            function renderFromCache() {
                MeetOffline.cachedConversations().then(function (list) {
                    if (!list) return;
                    var items = document.getElementById('conversation-list-items');
                    items.innerHTML = '';
                    list.forEach(function (conversation) { items.appendChild(renderConversationRow(conversation)); });
                    applyConversationFilter();
                }).catch(function () {});
            }

            function refresh() {
                axios.get('/api/v1/conversations', { params: { archived: archivedView ? 1 : 0 } }).then(function (response) {
                    MeetOffline.cacheConversations(response.data.data).catch(function () {});

                    response.data.data.forEach(function (conversation) {
                        var row = document.querySelector('[data-conversation-uuid="' + conversation.uuid + '"]');
                        if (!row) {
                            return;
                        }

                        var unreadBadge = row.querySelector('.conversation-unread');
                        if (conversation.unread_count > 0) {
                            unreadBadge.textContent = conversation.unread_count;
                            unreadBadge.classList.remove('hidden');
                        } else {
                            unreadBadge.classList.add('hidden');
                        }

                        var timeEl = row.querySelector('.conversation-time');
                        if (timeEl) renderConversationTime(timeEl, conversation.last_activity_at);

                        var lastMessage = row.querySelector('.conversation-last-message');
                        if (conversation.last_message) {
                            if (conversation.is_typing) {
                                lastMessage.textContent = 'typing…';
                            } else {
                                renderLastMessagePreview(lastMessage, conversation.id, conversation.last_message);
                            }
                        }
                    });
                }).catch(function (error) {
                    console.error('Failed to refresh conversation list', error);
                    // Offline (or the network request otherwise failed) -
                    // fall back to whatever was last cached rather than
                    // leaving a stale/empty list on screen.
                    if (!navigator.onLine) renderFromCache();
                });
            }

            refresh();
            setInterval(refresh, pollMs);

            // All/Chats/Groups tabs - a client-side filter over the
            // already-loaded rows (this list already interleaves 1:1
            // and group conversations for "All"), not a separate data
            // source per tab.
            var activeFilter = 'all';
            var filterTabs = document.querySelectorAll('.conversation-filter-tab');

            var FILTER_EMPTY_LABELS = { all: 'No conversations yet.', direct: 'No chats yet.', group: 'No groups yet.' };

            function applyConversationFilter() {
                var visibleCount = 0;
                document.querySelectorAll('#conversation-list-items > li[data-conversation-type]').forEach(function (row) {
                    var matches = activeFilter === 'all' || row.dataset.conversationType === activeFilter;
                    row.classList.toggle('hidden', !matches);
                    if (matches) visibleCount++;
                });

                var filterEmptyEl = document.getElementById('conversation-filter-empty');
                var totalRows = document.querySelectorAll('#conversation-list-items > li[data-conversation-type]').length;
                // Only this filter-specific message when there ARE
                // conversations overall but none in this particular tab -
                // the server-rendered @@empty block already covers having
                // zero conversations at all.
                var showFilterEmpty = totalRows > 0 && visibleCount === 0;
                filterEmptyEl.classList.toggle('hidden', !showFilterEmpty);
                if (showFilterEmpty) {
                    filterEmptyEl.textContent = FILTER_EMPTY_LABELS[activeFilter] || FILTER_EMPTY_LABELS.all;
                }
            }

            filterTabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    activeFilter = tab.dataset.conversationFilter;
                    filterTabs.forEach(function (t) {
                        t.classList.toggle('bg-brand-50', t === tab);
                        t.classList.toggle('text-brand-700', t === tab);
                        t.classList.toggle('text-gray-500', t !== tab);
                    });
                    applyConversationFilter();
                });
            });

            if (filterTabs.length) {
                filterTabs[0].classList.add('bg-brand-50', 'text-brand-700');
                filterTabs.forEach(function (t, i) { if (i > 0) t.classList.add('text-gray-500'); });
            }

            // Infinite scroll - loads further pages as the user scrolls
            // near the bottom. Appended rows aren't touched by refresh()
            // above (which only re-checks the first page), so their
            // unread count/last message won't live-update until reload -
            // an acceptable trade-off for how rarely anyone scrolls that
            // deep into their conversation list.
            (function () {
                var list = document.getElementById('conversation-list');
                var items = document.getElementById('conversation-list-items');
                var page = 1;
                var hasMore = list.dataset.hasMorePages === '1';
                var loading = false;

                function initials(name) {
                    return (name || '?').trim().charAt(0).toUpperCase();
                }

                function appendConversation(conversation) {
                    var isGroup = conversation.type === 'group';
                    var label = isGroup ? conversation.title : (conversation.other_user?.full_name || 'Unknown');

                    var li = document.createElement('li');
                    li.dataset.conversationUuid = conversation.uuid;
                    li.className = 'conversation-row relative overflow-hidden';
                    li.dataset.conversationId = conversation.id;
                    li.innerHTML =
                        conversationRowActionsHtml(conversation.id) +
                        '<div class="conversation-row-content group relative flex touch-pan-y items-center gap-2 bg-white px-2 transition-transform duration-200 ease-out">' +
                        '<input type="checkbox" class="conversation-select-checkbox ml-3 ' + (selectModeActive ? '' : 'hidden ') + 'h-4 w-4 shrink-0 rounded border-gray-300 text-brand-600 focus:ring-brand-500" data-conversation-id="' + conversation.id + '">' +
                        '<a href="/chat/' + conversation.id + '" class="flex min-w-0 flex-1 items-center gap-3 px-3 py-4 hover:bg-gray-50">' +
                        (!isGroup && conversation.other_user && conversation.other_user.profile_photo && /^(https?:\/\/|\/)/.test(conversation.other_user.profile_photo)
                            ? '<img src="' + encodeURI(conversation.other_user.profile_photo).replace(/"/g, '%22') + '" alt="" class="h-10 w-10 shrink-0 rounded-full" style="object-fit:cover">'
                            : '<span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full ' + (isGroup ? 'bg-emerald-100 text-emerald-700' : 'bg-brand-100 text-brand-700') + ' text-sm font-semibold">' +
                        initials(label) +
                        '</span>') +
                        '<span class="min-w-0 flex-1">' +
                        '<span class="flex items-center justify-between gap-2">' +
                        '<span class="truncate text-sm font-medium text-gray-900">' + (label || 'Unknown') + '</span>' +
                        '<span class="conversation-time shrink-0 text-xs text-gray-400"></span>' +
                        '</span>' +
                        '<span class="flex items-center justify-between gap-2">' +
                        '<span class="conversation-last-message min-w-0 flex-1 truncate text-sm text-gray-500"></span>' +
                        '<span class="conversation-unread shrink-0 hidden rounded-full bg-brand-600 px-2 py-0.5 text-xs font-semibold text-white"></span>' +
                        '</span>' +
                        '</span>' +
                        '</a>' +
                        '</div>';
                    li.dataset.conversationType = isGroup ? 'group' : 'direct';
                    renderConversationTime(li.querySelector('.conversation-time'), conversation.last_activity_at);
                    renderLastMessagePreview(li.querySelector('.conversation-last-message'), conversation.id, conversation.last_message);
                    items.appendChild(li);
                    applyConversationFilter();
                }

                // The app shell's <main> scrolls internally
                // (overflow-y-auto), not the window - window-level scroll
                // detection here would never fire.
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

                    axios.get('/api/v1/conversations', { params: { archived: archivedView ? 1 : 0, page: page } }).then(function (response) {
                        response.data.data.forEach(appendConversation);
                        hasMore = response.data.meta.has_more_pages;
                        loading = false;
                    }).catch(function () {
                        loading = false;
                    });
                }

                scrollContainer.addEventListener('scroll', loadMoreIfNeeded);
            })();

            // WhatsApp-style swipe-to-reveal Archive/Delete per row -
            // replaces what used to be a permanently-visible per-row
            // Archive button (users reported accidental archives just
            // from it always sitting there). Mirrors show.blade.php's own
            // swipe-to-reply touchstart/touchmove/touchend handling on
            // .message-bubble: one delegated listener set on the list
            // container, a horizontal-vs-vertical dx/dy check so a
            // vertical scroll is never mistaken for a swipe, and a
            // distance threshold - except here the drag is followed live
            // (translateX tracks the finger) rather than firing on
            // release only, since the whole point is sliding the row
            // aside to reveal the buttons underneath, not a one-shot
            // shortcut like swipe-to-reply is.
            //
            // No "Pin conversation" action here: unlike messages (which
            // do have pinning, see pinned_messages), there is no
            // pin-a-conversation-to-the-top feature anywhere in this
            // codebase to hook up, and inventing one (plus a backend
            // endpoint for it) is out of scope for this pass - Delete and
            // Archive are the only two per-row actions today, same as the
            // button they're replacing.
            (function () {
                var container = document.getElementById('conversation-list-items');
                var SWIPE_LOCK_PX = 10;
                var startX = null, startY = null, startOffset = 0, dragging = false, justDragged = false;
                var activeRow = null, activeContent = null, activeWidth = 0;
                var openRow = null, lastDraggedRow = null;

                function currentOffset(content) {
                    var match = /translateX\((-?\d+(?:\.\d+)?)px\)/.exec(content.style.transform || '');
                    return match ? parseFloat(match[1]) : 0;
                }

                function closeRow(row) {
                    if (!row) return;
                    var content = row.querySelector('.conversation-row-content');
                    if (content) content.style.transform = '';
                    row.classList.remove('conversation-row-open');
                    if (openRow === row) openRow = null;
                }

                container.addEventListener('touchstart', function (event) {
                    if (selectModeActive || event.touches.length !== 1) {
                        startX = null;
                        return;
                    }
                    var row = event.target.closest('.conversation-row');
                    if (!row) {
                        startX = null;
                        return;
                    }
                    if (openRow && openRow !== row) {
                        closeRow(openRow);
                    }
                    activeRow = row;
                    activeContent = row.querySelector('.conversation-row-content');
                    var actionsEl = row.querySelector('.conversation-row-actions');
                    activeWidth = actionsEl ? actionsEl.offsetWidth : 160;
                    startX = event.touches[0].clientX;
                    startY = event.touches[0].clientY;
                    startOffset = currentOffset(activeContent);
                    dragging = false;
                }, { passive: true });

                container.addEventListener('touchmove', function (event) {
                    if (startX === null || !activeContent || event.touches.length !== 1) {
                        return;
                    }
                    var dx = event.touches[0].clientX - startX;
                    var dy = Math.abs(event.touches[0].clientY - startY);
                    if (!dragging) {
                        if (Math.abs(dx) < SWIPE_LOCK_PX || dy > Math.abs(dx)) {
                            return; // still deciding, or a vertical scroll - let it pass through natively
                        }
                        dragging = true;
                    }
                    var next = Math.min(0, Math.max(-activeWidth, startOffset + dx));
                    activeContent.style.transition = 'none';
                    activeContent.style.transform = 'translateX(' + next + 'px)';
                }, { passive: true });

                container.addEventListener('touchend', function () {
                    if (!activeContent) {
                        startX = null;
                        return;
                    }
                    activeContent.style.transition = '';
                    if (dragging) {
                        var offset = currentOffset(activeContent);
                        if (offset < -(activeWidth / 2)) {
                            activeContent.style.transform = 'translateX(-' + activeWidth + 'px)';
                            activeRow.classList.add('conversation-row-open');
                            openRow = activeRow;
                        } else {
                            closeRow(activeRow);
                        }
                        justDragged = true;
                        lastDraggedRow = activeRow;
                        setTimeout(function () { justDragged = false; lastDraggedRow = null; }, 300);
                    }
                    startX = null;
                    startY = null;
                    dragging = false;
                    activeContent = null;
                    activeRow = null;
                }, { passive: true });

                // Tapping anywhere outside the open row closes it, same as
                // WhatsApp; tapping the open row itself (its link) closes
                // it too instead of also navigating into the chat. Scoped
                // to this specific row (not every click in the document)
                // so it never swallows unrelated taps elsewhere on the page.
                document.addEventListener('click', function (event) {
                    if (justDragged && lastDraggedRow && lastDraggedRow.contains(event.target)) {
                        event.preventDefault();
                        event.stopPropagation();
                        return;
                    }
                    if (!openRow) {
                        return;
                    }
                    if (openRow.contains(event.target)) {
                        if (!event.target.closest('[data-conversation-action]')) {
                            event.preventDefault();
                            closeRow(openRow);
                        }
                    } else {
                        closeRow(openRow);
                    }
                }, true);

                container.addEventListener('click', function (event) {
                    var actionBtn = event.target.closest('[data-conversation-action]');
                    if (!actionBtn) {
                        return;
                    }
                    event.preventDefault();
                    var id = actionBtn.dataset.conversationId;
                    var action = actionBtn.dataset.conversationAction;
                    var row = actionBtn.closest('.conversation-row');

                    if (action === 'archive') {
                        axios.post('/chat/' + id + '/archive', { unarchive: archivedView ? 1 : 0 }).then(function () {
                            row.remove();
                        }).catch(function (error) {
                            console.error('Failed to archive conversation', error);
                            closeRow(row);
                        });
                        return;
                    }

                    if (action === 'delete') {
                        MeetDialog.confirm('Delete this chat? This only removes it from your own list.', { danger: true }).then(function (confirmed) {
                            if (!confirmed) {
                                closeRow(row);
                                return;
                            }
                            axios.post('/chat/' + id + '/leave').then(function () {
                                row.remove();
                            }).catch(function (error) {
                                console.error('Failed to delete conversation', error);
                                closeRow(row);
                            });
                        });
                    }
                });
            })();

            // Pending incoming contact requests, inline - only on the
            // main (non-archived) chat list.
            if (!archivedView) {
                function loadContactRequests() {
                    var banner = document.getElementById('contact-requests-banner');
                    var currentUserUuid = banner.dataset.currentUserUuid;

                    axios.get('/api/v1/contacts/requests').then(function (response) {
                        var incoming = response.data.data.filter(function (r) { return r.receiver.uuid === currentUserUuid; });

                        banner.classList.toggle('hidden', incoming.length === 0);
                        document.getElementById('contact-requests-label').textContent =
                            incoming.length + (incoming.length === 1 ? ' contact request' : ' contact requests');

                        // Compact icon-badge variant on the page header,
                        // alongside the full expanded banner above.
                        var iconLink = document.getElementById('contact-requests-icon-link');
                        var iconBadge = document.getElementById('contact-requests-icon-badge');
                        iconLink.classList.toggle('hidden', incoming.length === 0);
                        iconBadge.textContent = incoming.length;

                        var list = document.getElementById('contact-requests-list');
                        list.innerHTML = '';

                        incoming.forEach(function (r) {
                            var li = document.createElement('li');
                            li.className = 'flex items-center justify-between gap-4 px-5 py-3';
                            li.innerHTML =
                                '<div class="flex min-w-0 items-center gap-3">' +
                                '<span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700">' +
                                (r.sender.full_name || '?').charAt(0).toUpperCase() +
                                '</span>' +
                                '<p class="truncate text-sm font-medium text-gray-900">' + (r.sender.full_name || 'Unknown') + '</p>' +
                                '</div>' +
                                '<div class="flex shrink-0 gap-2">' +
                                '<button type="button" data-action="accept" data-id="' + r.id + '" class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700">Accept</button>' +
                                '<button type="button" data-action="reject" data-id="' + r.id + '" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50">Decline</button>' +
                                '</div>';
                            list.appendChild(li);
                        });
                    }).catch(function () {});
                }

                document.getElementById('contact-requests-list').addEventListener('click', function (event) {
                    var button = event.target.closest('button[data-action]');
                    if (!button) {
                        return;
                    }

                    axios.post('/api/v1/contacts/request/' + button.dataset.id + '/' + button.dataset.action)
                        .then(loadContactRequests)
                        .catch(function () {});
                });

                loadContactRequests();
                setInterval(loadContactRequests, pollMs);
            }

            // WhatsApp-style multi-select: tap "Select" to reveal a
            // checkbox per row and a bulk-action bar in place of the
            // normal header, then Archive/Mute/Delete every checked
            // conversation at once via the existing single-conversation
            // endpoints (Modules\Chat\Http\Controllers\ChatWebController),
            // one request per id - no new bulk endpoint needed since
            // these actions are cheap and the list rarely exceeds a
            // couple dozen selected rows.
            (function () {
                var header = document.getElementById('chat-list-header');
                var bulkBar = document.getElementById('chat-bulk-bar');
                var selectBtn = document.getElementById('chat-select-btn');
                var cancelBtn = document.getElementById('chat-bulk-cancel-btn');
                var countEl = document.getElementById('chat-bulk-count');
                var archiveBtn = document.getElementById('chat-bulk-archive-btn');
                var muteBtn = document.getElementById('chat-bulk-mute-btn');
                var deleteBtn = document.getElementById('chat-bulk-delete-btn');
                var selected = new Set();

                function checkboxes() {
                    return document.querySelectorAll('.conversation-select-checkbox');
                }

                function updateCount() {
                    countEl.textContent = selected.size;
                    var hasSelection = selected.size > 0;
                    archiveBtn.disabled = !hasSelection;
                    muteBtn.disabled = !hasSelection;
                    deleteBtn.disabled = !hasSelection;
                }

                // Swiping a row open and entering multi-select are mutually
                // exclusive (same as WhatsApp) - resetting every row's
                // transform here also covers the case a row was left
                // swiped-open right before "Select" was tapped.
                function closeAllSwipedRows() {
                    document.querySelectorAll('.conversation-row-open').forEach(function (row) {
                        row.classList.remove('conversation-row-open');
                        var content = row.querySelector('.conversation-row-content');
                        if (content) content.style.transform = '';
                    });
                }

                function enterSelectMode() {
                    selectModeActive = true;
                    closeAllSwipedRows();
                    header.classList.add('hidden');
                    bulkBar.classList.remove('hidden');
                    bulkBar.classList.add('flex');
                    checkboxes().forEach(function (cb) { cb.classList.remove('hidden'); });
                }

                function exitSelectMode() {
                    selectModeActive = false;
                    selected.clear();
                    updateCount();
                    header.classList.remove('hidden');
                    bulkBar.classList.add('hidden');
                    bulkBar.classList.remove('flex');
                    checkboxes().forEach(function (cb) { cb.checked = false; cb.classList.add('hidden'); });
                }

                selectBtn.addEventListener('click', enterSelectMode);
                cancelBtn.addEventListener('click', exitSelectMode);

                document.getElementById('conversation-list-items').addEventListener('change', function (event) {
                    var checkbox = event.target.closest('.conversation-select-checkbox');
                    if (!checkbox) {
                        return;
                    }
                    var id = checkbox.dataset.conversationId;
                    if (checkbox.checked) {
                        selected.add(id);
                    } else {
                        selected.delete(id);
                    }
                    updateCount();
                });

                function applyToSelected(requestFn) {
                    var ids = Array.from(selected);
                    return Promise.all(ids.map(function (id) {
                        return requestFn(id).catch(function (error) {
                            console.error('Bulk action failed for conversation ' + id, error);
                        });
                    })).then(function () {
                        window.location.reload();
                    });
                }

                archiveBtn.addEventListener('click', function () {
                    applyToSelected(function (id) {
                        return axios.post('/chat/' + id + '/archive', { unarchive: archivedView ? 1 : 0 });
                    });
                });

                muteBtn.addEventListener('click', function () {
                    applyToSelected(function (id) {
                        return axios.post('/chat/' + id + '/mute', { muted: 1 });
                    });
                });

                deleteBtn.addEventListener('click', async function () {
                    var confirmed = await MeetDialog.confirm('Delete ' + selected.size + ' selected chat' + (selected.size === 1 ? '' : 's') + '? This only removes them from your own list.', { danger: true });
                    if (!confirmed) {
                        return;
                    }
                    applyToSelected(function (id) {
                        return axios.post('/chat/' + id + '/leave');
                    });
                });
            })();
        });
    </script>
</x-layouts.app>
