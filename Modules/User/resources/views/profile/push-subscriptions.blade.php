<x-layouts.app title="Push notification devices - Meet">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Push notification devices</h1>
            <p class="mt-1 text-sm text-gray-500">Browsers/devices currently subscribed to real push notifications.</p>
        </div>
        <a href="{{ route('profile.edit') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700">&larr; Back to settings</a>
    </div>

    <div class="rounded-2xl border border-gray-100 bg-white shadow-sm">
        <ul class="divide-y divide-gray-100">
            @forelse ($subscriptions as $subscription)
                <li class="flex items-center justify-between gap-4 px-6 py-4">
                    <div>
                        <p class="text-sm font-medium text-gray-900">{{ $subscription->user_agent ?: 'Unknown device' }}</p>
                        <p class="text-sm text-gray-500">Subscribed {{ $subscription->created_at->diffForHumans() }}</p>
                    </div>

                    <form method="POST" action="{{ route('profile.push-subscriptions.revoke', $subscription->uuid) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-700">Turn off</button>
                    </form>
                </li>
            @empty
                <li class="px-6 py-10 text-center text-sm text-gray-400">No devices are subscribed to push notifications yet.</li>
            @endforelse
        </ul>
    </div>
</x-layouts.app>
