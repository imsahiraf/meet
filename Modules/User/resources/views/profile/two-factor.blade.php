<x-layouts.app title="Two-factor authentication - Meet">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Two-factor authentication</h1>
            <p class="mt-1 text-sm text-gray-500">Require a code from an authenticator app (Google Authenticator, Authy, etc.) in addition to your password when logging in.</p>
        </div>
        <a href="{{ route('profile.security') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700">&larr; Back to Account &amp; Security</a>
    </div>

    <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
        @if (session('two_factor_recovery_codes'))
            <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4">
                <p class="text-sm font-semibold text-amber-800">Save these recovery codes somewhere safe</p>
                <p class="mt-1 text-xs text-amber-700">Each one can be used once to sign in if you lose access to your authenticator app. They won't be shown again.</p>
                <ul class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm text-amber-900">
                    @foreach (session('two_factor_recovery_codes') as $recoveryCode)
                        <li class="rounded bg-white px-2 py-1 text-center">{{ $recoveryCode }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($twoFactorSetup)
            <div class="rounded-lg border border-gray-200 p-4">
                <p class="text-sm text-gray-700">Scan this QR code with your authenticator app, then enter the 6-digit code it shows.</p>
                <img src="{{ $twoFactorSetup['qr_code'] }}" alt="Two-factor QR code" class="mt-3 h-40 w-40">
                <p class="mt-2 text-xs text-gray-400">Can't scan it? Enter this key manually: <span class="font-mono">{{ $twoFactorSetup['secret'] }}</span></p>

                <form method="POST" action="{{ route('profile.two-factor.confirm') }}" class="mt-4 flex max-w-xs items-end gap-2">
                    @csrf
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700">Code</label>
                        <input type="text" name="code" inputmode="numeric" maxlength="6" required
                               class="mt-1 block w-full rounded-lg border-gray-300 text-center tracking-[0.3em] shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                    </div>
                    <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-brand-700">Confirm</button>
                </form>
            </div>
        @elseif ($twoFactorEnabled)
            <div class="flex items-center gap-2 text-sm font-medium text-emerald-700">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" /><path d="m9 12 2 2 4-4" /></svg>
                Two-factor authentication is enabled
            </div>

            <form method="POST" action="{{ route('profile.two-factor.recovery-codes') }}" class="mt-4 flex max-w-sm items-end gap-2">
                @csrf
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700">Confirm password to regenerate recovery codes</label>
                    <input type="password" name="password" autocomplete="current-password" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                </div>
                <button type="submit" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Regenerate</button>
            </form>

            <form method="POST" action="{{ route('profile.two-factor.disable') }}" class="mt-3 flex max-w-sm items-end gap-2" id="disable-2fa-form">
                @csrf
                @method('DELETE')
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700">Confirm password to disable</label>
                    <input type="password" name="password" autocomplete="current-password" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                </div>
                <button type="submit" class="rounded-lg border border-red-200 px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50">Disable</button>
            </form>

            <a href="{{ route('profile.trusted-devices') }}" class="mt-3 inline-block text-sm font-medium text-brand-600 hover:text-brand-700">Manage devices that skip the code &rarr;</a>
        @else
            <form method="POST" action="{{ route('profile.two-factor.enable') }}">
                @csrf
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-brand-700">
                    Enable two-factor authentication
                </button>
            </form>
        @endif
    </section>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var form = document.getElementById('disable-2fa-form');
            if (form) {
                form.addEventListener('submit', async function (e) {
                    e.preventDefault();
                    if (!(await MeetDialog.confirm('Disable two-factor authentication?', {danger: true}))) {
                        return;
                    }
                    form.submit();
                });
            }
        });
    </script>
</x-layouts.app>
