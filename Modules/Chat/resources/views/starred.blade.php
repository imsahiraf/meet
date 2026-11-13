<x-layouts.app title="Starred messages - Meet">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold text-gray-900">Starred messages</h1>
        <a href="{{ route('chat.index') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700">&larr; Back to chats</a>
    </div>

    <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
        <ul class="divide-y divide-gray-100" id="starred-list">
            @forelse ($starred as $entry)
                <li data-starred-message-id="{{ $entry->message->id }}" class="flex items-start justify-between gap-4 px-5 py-4">
                    <a href="{{ route('chat.show', $entry->message->conversation_id) }}" class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900">{{ $entry->message->sender?->full_name ?? 'Unknown' }}</p>
                        {{-- The server can never decrypt an E2E message's body
                             (see IC-059) - a placeholder is shown here rather
                             than raw ciphertext; opening the conversation
                             itself still shows the real, decrypted text. --}}
                        <p class="mt-0.5 truncate text-sm text-gray-500">
                            {{ $entry->message->encryption_mode === 'e2e' ? '🔒 End-to-end encrypted message' : ($entry->message->body ?? 'Attachment') }}
                        </p>
                        <p class="mt-0.5 text-xs text-gray-400">{{ $entry->message->created_at?->format('M j, g:i A') }}</p>
                    </a>
                    <button type="button" data-unstar-message-id="{{ $entry->message->id }}" class="shrink-0 rounded-lg px-3 py-1.5 text-xs font-medium text-gray-500 hover:bg-gray-100">
                        Unstar
                    </button>
                </li>
            @empty
                <li class="px-5 py-14 text-center text-sm text-gray-400">No starred messages yet. Star a message in any chat to find it here later.</li>
            @endforelse
        </ul>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('starred-list').addEventListener('click', function (event) {
                var button = event.target.closest('button[data-unstar-message-id]');
                if (!button) return;

                axios.post('/api/v1/messages/' + button.dataset.unstarMessageId + '/star').then(function () {
                    var li = document.querySelector('[data-starred-message-id="' + button.dataset.unstarMessageId + '"]');
                    li?.remove();
                }).catch(function () {});
            });
        });
    </script>
</x-layouts.app>
