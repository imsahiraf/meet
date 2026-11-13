<x-layouts.app title="Active devices - Meet">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Active devices</h1>
            <p class="mt-1 text-sm text-gray-500">Sessions currently signed in to your account.</p>
        </div>
        <a href="{{ route('profile.edit') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700">&larr; Back to settings</a>
    </div>

    <div class="rounded-2xl border border-gray-100 bg-white shadow-sm">
        <ul class="divide-y divide-gray-100">
            @forelse ($sessions as $session)
                <li class="flex items-center justify-between gap-4 px-6 py-4">
                    <div>
                        <p class="text-sm font-medium text-gray-900">
                            {{ $session->browser ?: 'Unknown device' }}
                            @if ($session->session_id === session()->getId())
                                <span class="ml-2 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">This device</span>
                            @endif
                        </p>
                        <p class="text-sm text-gray-500">
                            {{ $session->ip_address }} &middot; last active {{ $session->last_activity_at?->diffForHumans() }}
                        </p>
                    </div>

                    @if ($session->session_id !== session()->getId())
                        <form method="POST" action="{{ route('profile.devices.revoke', $session->uuid) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-700">Sign out</button>
                        </form>
                    @endif
                </li>
            @empty
                <li class="px-6 py-10 text-center text-sm text-gray-400">No active sessions.</li>
            @endforelse
        </ul>
    </div>
</x-layouts.app>
