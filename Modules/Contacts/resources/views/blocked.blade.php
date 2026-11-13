<x-layouts.app title="Blocked users - Meet">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold text-gray-900">Blocked users</h1>
        <a href="{{ route('contacts.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 transition hover:bg-brand-50">
            &larr; Back to contacts
        </a>
    </div>

    <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
        <ul class="divide-y divide-gray-100">
            @forelse ($blocked as $entry)
                <li class="flex items-center justify-between gap-4 px-5 py-4 transition hover:bg-gray-50/80">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gray-100 text-sm font-semibold text-gray-500">
                            {{ strtoupper(substr($entry->blockedUser->full_name, 0, 1)) }}
                        </span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-gray-900">{{ $entry->blockedUser->full_name }}</p>
                            @if ($entry->reason)
                                <p class="truncate text-sm text-gray-500">{{ $entry->reason }}</p>
                            @endif
                        </div>
                    </div>
                    <form method="POST" action="{{ route('contacts.unblock', $entry->blocked_user_id) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="shrink-0 rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-600 transition hover:bg-gray-50">
                            Unblock
                        </button>
                    </form>
                </li>
            @empty
                <li class="px-5 py-10 text-center text-sm text-gray-400">No blocked users.</li>
            @endforelse
        </ul>
    </div>
</x-layouts.app>
