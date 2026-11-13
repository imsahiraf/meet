<x-layouts.app title="Trusted devices - Meet">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Trusted devices</h1>
            <p class="mt-1 text-sm text-gray-500">These devices skip the two-factor code for 30 days after you checked "Remember this device."</p>
        </div>
        <a href="{{ route('profile.edit') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700">&larr; Back to settings</a>
    </div>

    <div class="rounded-2xl border border-gray-100 bg-white shadow-sm">
        <ul class="divide-y divide-gray-100">
            @forelse ($devices as $device)
                <li class="flex items-center justify-between gap-4 px-6 py-4">
                    <div>
                        <p class="text-sm font-medium text-gray-900">{{ $device->browser ?: 'Unknown device' }}</p>
                        <p class="text-sm text-gray-500">
                            Trusted {{ $device->trusted_at->diffForHumans() }} &middot; expires {{ $device->expires_at?->diffForHumans() }}
                        </p>
                    </div>

                    <form method="POST" action="{{ route('profile.trusted-devices.revoke', $device) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-700">Forget</button>
                    </form>
                </li>
            @empty
                <li class="px-6 py-10 text-center text-sm text-gray-400">No trusted devices - every login will ask for a code.</li>
            @endforelse
        </ul>
    </div>
</x-layouts.app>
