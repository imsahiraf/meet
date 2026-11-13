<x-layouts.app title="Broadcast lists - Meet">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold text-gray-900">Broadcast lists</h1>
        <div class="flex items-center gap-3">
            <a href="{{ route('chat.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">&larr; Back to chats</a>
            <a href="{{ route('broadcast-lists.create') }}" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-700">New broadcast list</a>
        </div>
    </div>

    <p class="mb-4 text-sm text-gray-500">A message sent to a broadcast list goes out as separate direct messages - each recipient sees it as a normal chat from you, and replies stay private between you and them.</p>

    <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
        <ul class="divide-y divide-gray-100">
            @forelse ($lists as $list)
                <li class="flex items-center justify-between gap-4 px-5 py-4">
                    <div class="min-w-0">
                        <a href="{{ route('broadcast-lists.show', $list) }}" class="truncate text-sm font-medium text-gray-900 hover:text-brand-600">{{ $list->name }}</a>
                        <p class="text-xs text-gray-400">{{ $list->recipients_count }} {{ $list->recipients_count === 1 ? 'recipient' : 'recipients' }}</p>
                    </div>
                    <a href="{{ route('broadcast-lists.show', $list) }}" class="shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium text-brand-600 hover:bg-brand-50">Open</a>
                </li>
            @empty
                <li class="px-5 py-14 text-center text-sm text-gray-400">No broadcast lists yet.</li>
            @endforelse
        </ul>
    </div>
</x-layouts.app>
