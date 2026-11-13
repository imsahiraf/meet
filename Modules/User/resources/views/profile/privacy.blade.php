<x-layouts.app title="Privacy - Meet">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Privacy</h1>
            <p class="mt-1 text-sm text-gray-500">Control who can see your activity.</p>
        </div>
        <a href="{{ route('profile.edit') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700">&larr; Back to settings</a>
    </div>

    <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
        <form method="POST" action="{{ route('profile.privacy') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @csrf
            @method('PATCH')

            @foreach ([
                'last_seen_visibility' => 'Last seen',
                'profile_photo_visibility' => 'Profile photo',
                'about_visibility' => 'About',
            ] as $field => $label)
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ $label }}</label>
                    <select name="{{ $field }}" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                        @foreach (['everyone' => 'Everyone', 'contacts' => 'Contacts', 'nobody' => 'Nobody'] as $value => $optionLabel)
                            <option value="{{ $value }}" @selected(old($field, $user->privacy?->$field) === $value)>{{ $optionLabel }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="hidden" name="read_receipts_enabled" value="0">
                <input type="checkbox" name="read_receipts_enabled" value="1" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                       @checked(old('read_receipts_enabled', $user->privacy?->read_receipts_enabled))>
                Send read receipts
            </label>

            @if ($user->one_view_super_access)
                @php($presenceMode = old('fake_presence_mode', $user->privacy?->fake_presence_mode ?? 'real'))
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700">Presence override (Super Access)</label>
                    <select name="fake_presence_mode" id="fake-presence-mode" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                        <option value="real" @selected($presenceMode === 'real')>Show my real presence</option>
                        <option value="always_online" @selected($presenceMode === 'always_online')>Always show as online</option>
                        <option value="custom_offline" @selected($presenceMode === 'custom_offline')>Custom last-seen time (always offline)</option>
                    </select>
                    <p class="mt-1 text-xs text-gray-400">"Always online" needs no time set and stays on until you change it back. The custom option freezes your last-seen at a specific time you pick below.</p>
                </div>
                <div class="sm:col-span-2" id="fake-last-seen-field" @class(['hidden' => $presenceMode !== 'custom_offline'])>
                    <label class="block text-sm font-medium text-gray-700">Custom last seen time</label>
                    <input type="datetime-local" name="fake_last_seen_at"
                           value="{{ old('fake_last_seen_at', $user->privacy?->fake_last_seen_at?->format('Y-m-d\TH:i')) }}"
                           class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                </div>
                <script>
                    document.getElementById('fake-presence-mode').addEventListener('change', function () {
                        document.getElementById('fake-last-seen-field').classList.toggle('hidden', this.value !== 'custom_offline');
                    });
                </script>
            @endif

            <div class="sm:col-span-2">
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-brand-700">
                    Save privacy settings
                </button>
            </div>
        </form>
    </section>
</x-layouts.app>
