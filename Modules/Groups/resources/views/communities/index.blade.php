<x-layouts.app title="Communities - Meet">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Communities</h1>
            <a href="{{ route('groups.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">&larr; Back to groups</a>
        </div>
        <a href="{{ route('communities.create') }}" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700">
            + New community
        </a>
    </div>

    <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
        <ul class="divide-y divide-gray-100">
            @forelse ($communities as $community)
                <li class="flex items-center justify-between gap-4 px-5 py-4 transition hover:bg-gray-50/80">
                    <a href="{{ route('communities.show', $community) }}" class="flex min-w-0 flex-1 items-center gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-purple-100 text-sm font-semibold text-purple-700">
                            {{ strtoupper(substr($community->name, 0, 1)) }}
                        </span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium text-gray-900">{{ $community->name }}</span>
                            <span class="block truncate text-sm text-gray-500">{{ $community->groups_count }} {{ $community->groups_count === 1 ? 'group' : 'groups' }}</span>
                        </span>
                    </a>
                </li>
            @empty
                <li class="px-5 py-14 text-center text-sm text-gray-400">No communities yet.</li>
            @endforelse
        </ul>
    </div>
</x-layouts.app>
