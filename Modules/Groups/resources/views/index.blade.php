<x-layouts.app title="Groups - Meet">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-y-2 gap-x-4">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Groups</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $groups->total() }} {{ $groups->total() === 1 ? 'group' : 'groups' }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('communities.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">Communities</a>
            <a href="{{ route('groups.create') }}" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700">
                + New group
            </a>
        </div>
    </div>

    <form method="GET" action="{{ route('groups.index') }}" class="relative mb-6">
        <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35m1.35-5.65a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Search your groups&hellip;" class="!pl-10">
    </form>

    <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
        <ul class="divide-y divide-gray-100">
            @forelse ($groups as $group)
                <li class="flex items-center justify-between gap-4 px-5 py-4 transition hover:bg-gray-50/80">
                    <a href="{{ route('groups.show', $group) }}" class="flex min-w-0 flex-1 items-center gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-sm font-semibold text-emerald-700">
                            {{ strtoupper(substr($group->name, 0, 1)) }}
                        </span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium text-gray-900">{{ $group->name }}</span>
                            <span class="block truncate text-sm text-gray-500">{{ $group->member_count }} {{ $group->member_count === 1 ? 'member' : 'members' }}</span>
                        </span>
                    </a>
                    <a href="{{ route('chat.show', $group->conversation) }}" class="shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium text-brand-600 transition hover:bg-brand-50">
                        Open chat
                    </a>
                </li>
            @empty
                <li class="px-5 py-14 text-center">
                    <p class="text-sm font-medium text-gray-900">No groups yet</p>
                    <p class="mt-1 text-sm text-gray-500">Create a group to start chatting with more than one person at a time.</p>
                </li>
            @endforelse
        </ul>
    </div>

    <div class="mt-6">
        {{ $groups->links() }}
    </div>
</x-layouts.app>
