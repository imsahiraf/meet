<x-layouts.app title="Dashboard - Meet">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">Welcome back, {{ explode(' ', auth()->user()->full_name)[0] }} 👋</h1>
        <p class="mt-1 text-sm text-gray-500">Here's what's happening on Meet.</p>
    </div>

    <div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
            <p class="text-sm text-gray-500">Contacts</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900">{{ $contactsCount }}</p>
            <a href="{{ route('contacts.index') }}" class="mt-2 inline-block text-sm font-medium text-brand-600 hover:text-brand-700">View contacts &rarr;</a>
        </div>

        <div class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
            <p class="text-sm text-gray-500">Pending requests</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900">{{ $pendingRequestsCount }}</p>
            <a href="{{ route('contacts.requests') }}" class="mt-2 inline-block text-sm font-medium text-brand-600 hover:text-brand-700">Review requests &rarr;</a>
        </div>

        <div class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
            <p class="text-sm text-gray-500">Conversations</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900">{{ $recentConversations->count() }}</p>
            <a href="{{ route('chat.index') }}" class="mt-2 inline-block text-sm font-medium text-brand-600 hover:text-brand-700">Open chat &rarr;</a>
        </div>

        <div class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
            <p class="text-sm text-gray-500">Meetings</p>
            <div class="mt-2 flex gap-2">
                <form method="POST" action="{{ route('meetings.instant') }}">
                    @csrf
                    <button type="submit" class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-700">New meeting</button>
                </form>
                <a href="{{ route('meetings.create') }}" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">Schedule</a>
            </div>
        </div>
    </div>

    <div class="rounded-2xl border border-gray-100 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
            <h2 class="text-sm font-semibold text-gray-900">Recent conversations</h2>
            <a href="{{ route('chat.index') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700">See all</a>
        </div>

        @if ($recentConversations->isEmpty())
            <div class="px-5 py-10 text-center text-sm text-gray-400">
                No conversations yet.
                <a href="{{ route('contacts.index') }}" class="font-medium text-brand-600 hover:text-brand-700">Find a contact</a> to start chatting.
            </div>
        @else
            <ul class="divide-y divide-gray-100">
                @foreach ($recentConversations as $conversation)
                    @php
                        $me = auth()->id();
                        $other = $conversation->members->firstWhere('user_id', '!=', $me)?->user;
                    @endphp
                    <li>
                        <a href="{{ route('chat.show', $conversation) }}" class="flex items-center gap-3 px-5 py-4 hover:bg-gray-50">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700">
                                {{ strtoupper(substr($other?->full_name ?? '?', 0, 1)) }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-gray-900">{{ $other?->full_name ?? 'Unknown' }}</span>
                                {{-- An E2E last message is ciphertext on the server: show a placeholder, the script below decrypts it --}}
                                @php $last = $conversation->lastMessage; $isE2e = $last?->encryption_mode === 'e2e'; @endphp
                                <span class="dashboard-last-message block truncate text-sm text-gray-500"
                                      @if ($isE2e) data-conversation-id="{{ $conversation->id }}" data-body="{{ $last->body }}" data-iv="{{ $last->iv }}" data-key-version="{{ $last->key_version }}" @endif>
                                    {{ $isE2e ? '🔒 Encrypted message' : ($last ? ($last->body ?: 'Attachment') : 'No messages yet') }}
                                </span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var rows = document.querySelectorAll('.dashboard-last-message[data-body]');
            if (!rows.length || !window.MeetE2EReady) return;
            window.MeetE2EReady.then(function (ready) {
                if (!ready) return;
                rows.forEach(function (el) {
                    var version = el.dataset.keyVersion ? parseInt(el.dataset.keyVersion, 10) : null;
                    MeetE2E.getConversationKey(el.dataset.conversationId, version).then(function (key) {
                        if (!key) return;
                        return MeetE2E.decryptText(el.dataset.body, el.dataset.iv, key).then(function (text) {
                            el.textContent = text;
                        });
                    }).catch(function () {});
                });
            });
        });
    </script>
</x-layouts.app>
