<x-layouts.app title="Switch account - Meet">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Switch account</h1>
            <p class="mt-1 text-sm text-gray-500">Jump to another account you've logged into on this device, no password needed.</p>
        </div>
        <a href="{{ route('profile.edit') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700">&larr; Back to settings</a>
    </div>

    <div class="rounded-2xl border border-gray-100 bg-white shadow-sm">
        <ul class="divide-y divide-gray-100">
            @forelse ($accounts as $account)
                <li class="flex items-center justify-between gap-4 px-6 py-4">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700">
                            {{ strtoupper(substr($account['full_name'], 0, 1)) }}
                        </span>
                        <span class="truncate text-sm font-medium text-gray-900">{{ $account['full_name'] }}</span>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <form method="POST" action="{{ route('accounts.switch', $account['user_id']) }}">
                            @csrf
                            <button type="submit" class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700">Switch to</button>
                        </form>
                        <form method="POST" action="{{ route('accounts.forget', $account['user_id']) }}">
                            @csrf
                            <button type="submit" class="text-sm font-medium text-gray-400 hover:text-red-600">Forget</button>
                        </form>
                    </div>
                </li>
            @empty
                <li class="px-6 py-10 text-center text-sm text-gray-400">No other accounts remembered on this device yet.</li>
            @endforelse
        </ul>
    </div>

    <a href="{{ route('accounts.add.show') }}" class="mt-4 inline-block text-sm font-medium text-brand-600 hover:text-brand-700">+ Add another account &rarr;</a>
</x-layouts.app>
