<x-layouts.guest title="Log in - Meet">
    @if (count($switchableAccounts ?? []) > 0)
        <h1 class="text-xl font-semibold text-gray-900">Choose an account</h1>
        <p class="mt-1 text-sm text-gray-500">Continue as one you've used on this device, or log in below.</p>

        <div class="mt-4 space-y-2">
            @foreach ($switchableAccounts as $account)
                <div class="flex items-center gap-2 rounded-lg border border-gray-100 hover:bg-gray-50">
                    <form method="POST" action="{{ route('accounts.switch', $account['user_id']) }}" class="flex-1">
                        @csrf
                        <button type="submit" class="flex w-full items-center gap-3 px-3 py-2.5 text-left">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700">
                                {{ strtoupper(substr($account['full_name'], 0, 1)) }}
                            </span>
                            <span class="truncate text-sm font-medium text-gray-900">{{ $account['full_name'] }}</span>
                        </button>
                    </form>
                    <form method="POST" action="{{ route('accounts.forget', $account['user_id']) }}">
                        @csrf
                        <button type="submit" class="mr-2 rounded-lg px-2 py-1 text-xs font-medium text-gray-400 hover:bg-red-50 hover:text-red-600" title="Remove from this device">Remove</button>
                    </form>
                </div>
            @endforeach
        </div>

        <div class="mt-6 flex items-center gap-3 text-xs font-medium uppercase tracking-wide text-gray-400">
            <span class="h-px flex-1 bg-gray-100"></span>
            <span>Or log in to another account</span>
            <span class="h-px flex-1 bg-gray-100"></span>
        </div>
    @else
        <h1 class="text-xl font-semibold text-gray-900">Welcome back</h1>
        <p class="mt-1 text-sm text-gray-500">Log in to continue to Meet.</p>
    @endif

    <form method="POST" action="{{ route('login.attempt') }}" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="login" class="block text-sm font-medium text-gray-700">Email or phone number</label>
            <input id="login" name="login" type="text" value="{{ old('login') }}" required autofocus
                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
        </div>

        <div>
            <div class="flex items-center justify-between">
                <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                <a href="{{ route('password.request') }}" class="text-sm text-brand-600 hover:text-brand-700">Forgot password?</a>
            </div>
            <input id="password" name="password" type="password" required
                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-600">
            <input type="checkbox" name="remember" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500" @checked(old('remember', true))>
            Remember me
        </label>

        <button type="submit"
                class="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-brand-600/30 transition hover:bg-brand-700">
            Log in
        </button>
    </form>

    <div class="mt-4" id="passkey-login-row" hidden>
        <button type="button" id="passkey-login-btn"
                class="w-full rounded-lg border border-gray-200 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
            Sign in with a passkey
        </button>
        <p id="passkey-login-error" class="mt-2 hidden text-center text-sm text-red-600"></p>
    </div>

    @if (config('services.google.client_id'))
        <a href="{{ route('sso.redirect', 'google') }}"
           class="mt-4 flex w-full items-center justify-center gap-2 rounded-lg border border-gray-200 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
            <svg class="h-4 w-4" viewBox="0 0 24 24"><path fill="#4285F4" d="M23.52 12.27c0-.85-.08-1.67-.22-2.46H12v4.66h6.47a5.53 5.53 0 0 1-2.4 3.63v3h3.89c2.28-2.1 3.6-5.2 3.6-8.83z"/><path fill="#34A853" d="M12 24c3.24 0 5.96-1.07 7.95-2.9l-3.89-3a7.4 7.4 0 0 1-11-3.9H1.06v3.1A12 12 0 0 0 12 24z"/><path fill="#FBBC05" d="M5.06 14.2a7.2 7.2 0 0 1 0-4.4v-3.1H1.06a12 12 0 0 0 0 10.6z"/><path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.44-3.44A11.6 11.6 0 0 0 12 0 12 12 0 0 0 1.06 6.7l4 3.1A7.16 7.16 0 0 1 12 4.75z"/></svg>
            Continue with Google
        </a>
    @endif
    <x-auth.insights-button />

    <p class="mt-6 text-center text-sm text-gray-500">
        Don't have an account?
        <a href="{{ route('register') }}" class="font-medium text-brand-600 hover:text-brand-700">Sign up</a>
    </p>

    <div class="mt-8 border-t border-gray-100 pt-6">
        <p class="text-center text-xs font-medium uppercase tracking-wide text-gray-400">Just here for a meeting?</p>
        <div class="mt-3 flex flex-col gap-2 sm:flex-row">
            <a href="{{ route('meetings.guest-create-form') }}" class="flex-1 rounded-lg border border-gray-200 px-4 py-2 text-center text-sm font-medium text-gray-700 hover:bg-gray-50">New meeting</a>
            <button type="button" id="guest-join-code-btn" class="flex-1 rounded-lg border border-gray-200 px-4 py-2 text-center text-sm font-medium text-gray-700 hover:bg-gray-50">Join with a code</button>
        </div>
        <div id="guest-join-code-row" class="mt-3 hidden gap-2">
            <input type="text" id="guest-join-code-input" placeholder="Meeting code" class="flex-1 rounded-lg border-gray-300 text-base shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
            <button type="button" id="guest-join-code-go" class="rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">Go</button>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var toggleBtn = document.getElementById('guest-join-code-btn');
            var row = document.getElementById('guest-join-code-row');
            var goBtn = document.getElementById('guest-join-code-go');
            var input = document.getElementById('guest-join-code-input');
            if (!toggleBtn) return;

            toggleBtn.addEventListener('click', function () {
                row.classList.toggle('hidden');
                row.classList.toggle('flex');
                if (!row.classList.contains('hidden')) input.focus();
            });

            goBtn.addEventListener('click', function () {
                var code = input.value.trim();
                if (code) window.location.href = '/j/' + encodeURIComponent(code);
            });

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') goBtn.click();
            });
        });

        // Passkey login - only shown when the browser actually supports
        // the WebAuthn Level 3 JSON helpers (parseRequestOptionsFromJSON /
        // credential.toJSON()), which avoids hand-rolling base64url
        // encode/decode for the challenge/credential-id fields ourselves.
        document.addEventListener('DOMContentLoaded', function () {
            var passkeyRow = document.getElementById('passkey-login-row');
            var passkeyBtn = document.getElementById('passkey-login-btn');
            var errorEl = document.getElementById('passkey-login-error');
            if (!window.PublicKeyCredential || typeof PublicKeyCredential.parseRequestOptionsFromJSON !== 'function') {
                return;
            }
            passkeyRow.hidden = false;

            passkeyBtn.addEventListener('click', function () {
                errorEl.classList.add('hidden');
                passkeyBtn.disabled = true;

                axios.get('{{ route('passkey.login-options') }}').then(function (response) {
                    var options = PublicKeyCredential.parseRequestOptionsFromJSON(response.data.options);
                    return navigator.credentials.get({ publicKey: options });
                }).then(function (credential) {
                    return axios.post('{{ route('passkey.login') }}', { credential: credential.toJSON() });
                }).then(function (response) {
                    window.location.href = response.data.redirect;
                }).catch(function (error) {
                    passkeyBtn.disabled = false;
                    var message = error?.response?.data?.errors?.credential?.[0]
                        || error?.response?.data?.message
                        || 'Unable to sign in with a passkey.';
                    errorEl.textContent = message;
                    errorEl.classList.remove('hidden');
                });
            });
        });
    </script>
</x-layouts.guest>
