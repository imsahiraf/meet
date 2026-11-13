<x-layouts.app title="Notifications - Meet">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Notifications</h1>
            <p class="mt-1 text-sm text-gray-500">Choose what you get notified about.</p>
        </div>
        <a href="{{ route('profile.edit') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700">&larr; Back to settings</a>
    </div>

    <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
        <form method="POST" action="{{ route('profile.preferences') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            @csrf
            @method('PATCH')

            @foreach ([
                'notify_messages' => 'Messages',
                'notify_calls' => 'Calls',
                'notify_meetings' => 'Meetings',
                'notify_contact_requests' => 'Contact requests',
            ] as $field => $label)
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="hidden" name="{{ $field }}" value="0">
                    <input type="checkbox" name="{{ $field }}" value="1" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                           @checked(old($field, $user->preferences?->$field))>
                    {{ $label }}
                </label>
            @endforeach

            <div class="sm:col-span-2">
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-brand-700">
                    Save notifications
                </button>
            </div>
        </form>

        <a href="{{ route('profile.push-subscriptions') }}" class="mt-4 inline-block text-sm font-medium text-brand-600 hover:text-brand-700">Manage push notification devices &rarr;</a>
    </section>
</x-layouts.app>
