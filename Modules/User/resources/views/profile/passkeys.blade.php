<x-layouts.app title="Passkeys - Meet">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Passkeys</h1>
            <p class="mt-1 text-sm text-gray-500">Sign in with your device's fingerprint, face, or screen lock instead of a password.</p>
        </div>
        <a href="{{ route('profile.security') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700">&larr; Back to Account &amp; Security</a>
    </div>

    <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
        @if ($passkeys->isNotEmpty())
            <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200">
                @foreach ($passkeys as $passkey)
                    <li class="flex items-center justify-between gap-3 px-4 py-3">
                        <div>
                            <p class="text-sm font-medium text-gray-900">{{ $passkey->name }}</p>
                            <p class="text-xs text-gray-400">
                                {{ $passkey->authenticator ?? 'Unknown authenticator' }}
                                &middot;
                                {{ $passkey->last_used_at ? 'Last used ' . $passkey->last_used_at->diffForHumans() : 'Never used' }}
                            </p>
                        </div>
                        <form method="POST" action="{{ route('profile.passkeys.destroy', $passkey) }}"
                              class="flex items-center gap-2 js-confirm-remove-passkey-form">
                            @csrf
                            @method('DELETE')
                            <input type="password" name="password" placeholder="Password" autocomplete="current-password" required
                                   class="w-32 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                            <button type="submit" class="rounded-lg border border-red-200 px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50">Remove</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif

        <div id="passkey-add-row" class="mt-3 hidden gap-2">
            <input type="text" id="passkey-name-input" placeholder="e.g. My laptop" maxlength="255"
                   class="flex-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            <button type="button" id="passkey-add-confirm-btn" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-brand-700">Save</button>
            <button type="button" id="passkey-add-cancel-btn" class="rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
        </div>
        <button type="button" id="passkey-add-btn" hidden class="mt-3 rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
            Add a passkey
        </button>
        <p id="passkey-add-error" class="mt-2 hidden text-sm text-red-600"></p>
    </section>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var addBtn = document.getElementById('passkey-add-btn');
            var addRow = document.getElementById('passkey-add-row');
            var nameInput = document.getElementById('passkey-name-input');
            var confirmBtn = document.getElementById('passkey-add-confirm-btn');
            var cancelBtn = document.getElementById('passkey-add-cancel-btn');
            var errorEl = document.getElementById('passkey-add-error');

            document.querySelectorAll('.js-confirm-remove-passkey-form').forEach(function (form) {
                form.addEventListener('submit', async function (e) {
                    e.preventDefault();
                    if (!(await MeetDialog.confirm('Remove this passkey?', {danger: true}))) {
                        return;
                    }
                    form.submit();
                });
            });

            if (!window.PublicKeyCredential || typeof PublicKeyCredential.parseCreationOptionsFromJSON !== 'function') {
                return;
            }
            addBtn.hidden = false;

            addBtn.addEventListener('click', function () {
                addBtn.hidden = true;
                addRow.classList.remove('hidden');
                addRow.classList.add('flex');
                nameInput.focus();
            });

            cancelBtn.addEventListener('click', function () {
                addRow.classList.add('hidden');
                addRow.classList.remove('flex');
                addBtn.hidden = false;
                errorEl.classList.add('hidden');
            });

            confirmBtn.addEventListener('click', function () {
                var name = nameInput.value.trim();
                if (!name) {
                    nameInput.focus();
                    return;
                }

                errorEl.classList.add('hidden');
                confirmBtn.disabled = true;

                axios.get('{{ route('profile.passkeys.options') }}').then(function (response) {
                    var options = PublicKeyCredential.parseCreationOptionsFromJSON(response.data.options);
                    return navigator.credentials.create({ publicKey: options });
                }).then(function (credential) {
                    return axios.post('{{ route('profile.passkeys.store') }}', {
                        name: name,
                        credential: credential.toJSON(),
                    });
                }).then(function () {
                    window.location.reload();
                }).catch(function (error) {
                    confirmBtn.disabled = false;
                    var message = error?.response?.data?.errors?.credential?.[0]
                        || error?.response?.data?.message
                        || 'Unable to add this passkey.';
                    errorEl.textContent = message;
                    errorEl.classList.remove('hidden');
                });
            });
        });
    </script>
</x-layouts.app>
