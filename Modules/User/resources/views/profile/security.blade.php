<x-layouts.app title="Account & Security - Meet">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Account &amp; Security</h1>
            <p class="mt-1 text-sm text-gray-500">Manage how you sign in and who has access to your account.</p>
        </div>
        <a href="{{ route('profile.edit') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700">&larr; Back to settings</a>
    </div>

    <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
        <ul class="divide-y divide-gray-100">
            @foreach ([
                ['route' => 'profile.password.show', 'icon' => 'lock', 'label' => 'Change password', 'description' => 'Update the password you sign in with'],
                ['route' => 'profile.two-factor.show', 'icon' => 'shield', 'label' => 'Two-factor authentication', 'description' => 'Require a code from an authenticator app at sign-in'],
                ['route' => 'profile.passkeys.show', 'icon' => 'fingerprint', 'label' => 'Passkeys', 'description' => 'Sign in with your fingerprint, face, or screen lock'],
                ['route' => 'profile.oauth.show', 'icon' => 'link', 'label' => 'Connected accounts', 'description' => 'Accounts you can sign in with instead of a password'],
                ['route' => 'profile.devices', 'icon' => 'devices', 'label' => 'Active devices', 'description' => 'See where you\'re signed in and sign out remotely'],
                ['route' => 'profile.switch-accounts', 'icon' => 'user', 'label' => 'Switch account', 'description' => 'Jump to another account on this device'],
                ['route' => 'accounts.add.show', 'icon' => 'user-plus', 'label' => 'Add account', 'description' => 'Sign in to another account on this device'],
                ['route' => 'profile.deactivate.show', 'icon' => 'trash', 'label' => 'Deactivate account', 'description' => 'Sign out everywhere - an admin can restore it later'],
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

    {{-- Logout lives here now, not as a persistent header button -
         deliberately separate from "Deactivate account" above (a much
         bigger, destructive-ish action): this just ends the current
         session(s), nothing about the account itself changes. --}}
    <div class="mt-6 overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
        <ul class="divide-y divide-gray-100">
            <li>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="flex w-full items-center gap-4 px-5 py-4 text-left transition hover:bg-gray-50/80">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-600">
                            <x-icons.logout class="h-5 w-5" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium text-gray-900">Logout</span>
                            <span class="block text-sm text-gray-500">Sign out of this account on this device</span>
                        </span>
                    </button>
                </form>
            </li>
            @if (count($otherSwitchableAccounts ?? []) > 0)
                <li>
                    <form method="POST" action="{{ route('logout.all') }}" id="logout-all-form">
                        @csrf
                        <button type="submit" class="flex w-full items-center gap-4 px-5 py-4 text-left transition hover:bg-gray-50/80">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600">
                                <x-icons.logout class="h-5 w-5" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium text-gray-900">Logout of all accounts</span>
                                <span class="block text-sm text-gray-500">Also signs out every other account linked on this device</span>
                            </span>
                        </button>
                    </form>
                </li>
            @endif
        </ul>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var form = document.getElementById('logout-all-form');
            if (form) {
                form.addEventListener('submit', async function (e) {
                    e.preventDefault();
                    if (!(await MeetDialog.confirm('Log out of every account linked on this device?'))) {
                        return;
                    }
                    form.submit();
                });
            }
        });
    </script>
</x-layouts.app>
