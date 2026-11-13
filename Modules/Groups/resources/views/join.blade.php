<x-layouts.guest title="Join group - Meet">
    <div class="text-center">
        <span class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-xl font-semibold text-emerald-700">
            {{ strtoupper(substr($invite->group->name, 0, 1)) }}
        </span>
        <h1 class="text-lg font-semibold text-gray-900">Join &ldquo;{{ $invite->group->name }}&rdquo;?</h1>
        <p class="mt-1 text-sm text-gray-500">{{ $invite->group->activeMembers()->count() }} members</p>

        @if ($invite->isExpired())
            <p class="mt-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">This invite link has expired.</p>
        @else
            <form method="POST" action="{{ route('groups.join', $invite->invite_token) }}" class="mt-6">
                @csrf
                <button type="submit" class="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700">
                    Join group
                </button>
            </form>
        @endif
    </div>
</x-layouts.guest>
