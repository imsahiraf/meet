<x-layouts.app title="Settings - Meet">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">Settings</h1>
        <p class="mt-1 text-sm text-gray-500">Manage your profile, preferences, and privacy.</p>
    </div>

    {{-- Dashboard/Admin live in the desktop sidebar's icon rail, but the
         mobile bottom bar only has room for the 5 main sections - this
         is the mobile entry point for them instead, reached by tapping
         your own avatar in the header. --}}
    <div class="mb-6 flex gap-2 lg:hidden">
        <a href="{{ route('dashboard') }}" class="flex-1 rounded-xl border border-gray-100 bg-white px-4 py-3 text-center text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">Dashboard</a>
        @if (auth()->user()->admin)
            <a href="{{ route('admin.dashboard') }}" class="flex-1 rounded-xl border border-gray-100 bg-white px-4 py-3 text-center text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">Admin</a>
        @endif
    </div>

    <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
        <ul class="divide-y divide-gray-100">
            @foreach ([
                ['route' => 'profile.info', 'icon' => 'user', 'label' => 'Profile', 'description' => 'Name, date of birth, gender, bio'],
                ['route' => 'profile.preferences.show', 'icon' => 'sliders', 'label' => 'Preferences', 'description' => 'Theme, language, timezone, media downloads'],
                ['route' => 'profile.notifications', 'icon' => 'bell', 'label' => 'Notifications', 'description' => 'What you get notified about'],
                ['route' => 'profile.chat-defaults', 'icon' => 'wallpaper', 'label' => 'Chat defaults', 'description' => 'Default wallpaper and font size for new chats'],
                ['route' => 'profile.privacy.show', 'icon' => 'eye', 'label' => 'Privacy', 'description' => 'Who can see your activity'],
                ['route' => 'profile.security', 'icon' => 'shield', 'label' => 'Account & Security', 'description' => 'Password, two-factor, passkeys, devices, deactivation'],
            ] as $item)
                <li>
                    <a href="{{ route($item['route']) }}" class="flex items-center gap-4 px-5 py-4 transition hover:bg-gray-50/80">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600">
                            <x-dynamic-component :component="'icons.' . $item['icon']" class="h-5 w-5" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium text-gray-900">{{ $item['label'] }}</span>
                            <span class="block text-sm text-gray-500">{{ $item['description'] }}</span>
                        </span>
                        <span class="shrink-0 text-gray-300">&rarr;</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</x-layouts.app>
