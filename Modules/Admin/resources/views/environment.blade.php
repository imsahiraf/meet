<x-layouts.app title="Environment - Meet">
    @include('admin::partials.nav')

    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Environment variables</h1>
            <p class="mt-1 text-sm text-gray-500">Super Admin only. Every save requires your account password, and a dated backup of the current file is kept before any change.</p>
        </div>
    </div>

    @if (count($missingKeys) > 0)
        <div class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            <p class="font-semibold">{{ count($missingKeys) }} key(s) from .env.example are missing or blank in the live environment:</p>
            <p class="mt-1 font-mono text-xs">{{ implode(', ', $missingKeys) }}</p>
            <p class="mt-2 text-xs text-amber-700">Usually means a new version added configuration a previous deploy didn't have a value for yet - fill these in below.</p>
        </div>
    @endif

    <div class="mb-6 flex gap-1 rounded-xl border border-gray-100 bg-white p-1 shadow-sm" role="tablist">
        <button type="button" data-env-tab="fields" class="env-tab flex-1 rounded-lg px-3 py-1.5 text-sm font-medium">Field editor</button>
        <button type="button" data-env-tab="raw" class="env-tab flex-1 rounded-lg px-3 py-1.5 text-sm font-medium">Raw file editor</button>
    </div>

    <div id="env-panel-fields" class="env-panel">
        <form method="POST" action="{{ route('admin.environment.fields') }}" class="rounded-2xl border border-gray-100 bg-white shadow-sm">
            @csrf
            <div class="max-h-[32rem] divide-y divide-gray-100 overflow-y-auto">
                @forelse ($envVars as $key => $value)
                    @php $isSensitive = (bool) preg_match('/KEY|SECRET|PASSWORD|TOKEN|PASS\b/i', $key); @endphp
                    <div class="flex items-center gap-3 px-5 py-3">
                        <label for="env-{{ $key }}" class="w-1/3 shrink-0 truncate font-mono text-xs font-medium text-gray-600" title="{{ $key }}">
                            {{ $key }}
                            @if (in_array($key, $missingKeys, true))
                                <span class="ml-1 rounded bg-amber-100 px-1 text-[10px] font-semibold text-amber-700">blank</span>
                            @endif
                        </label>
                        <input
                            id="env-{{ $key }}"
                            type="{{ $isSensitive ? 'password' : 'text' }}"
                            name="env[{{ $key }}]"
                            value="{{ $value }}"
                            autocomplete="off"
                            class="min-w-0 flex-1 rounded-lg border-gray-300 font-mono text-xs shadow-sm focus:border-brand-500 focus:ring-brand-500"
                        >
                        @if ($isSensitive)
                            <button type="button" class="env-toggle-visibility shrink-0 rounded-lg px-2 py-1 text-xs font-medium text-gray-500 hover:bg-gray-100" data-target="env-{{ $key }}">Show</button>
                        @endif
                    </div>
                @empty
                    <p class="px-5 py-10 text-center text-sm text-gray-400">No .env file found on this server.</p>
                @endforelse
            </div>

            @if (count($envVars) > 0)
                <div class="border-t border-gray-100 px-5 py-4">
                    <label class="block max-w-xs text-sm font-medium text-gray-700">Confirm your password
                        <input type="password" name="password" autocomplete="current-password" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                    </label>
                    @error('password')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                    <button type="submit" class="mt-3 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-700">Save changes</button>
                </div>
            @endif
        </form>
    </div>

    <div id="env-panel-raw" class="env-panel hidden">
        <form method="POST" action="{{ route('admin.environment.raw') }}" class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
            @csrf
            <textarea name="raw" spellcheck="false" class="h-96 w-full rounded-lg border-gray-300 font-mono text-xs shadow-sm focus:border-brand-500 focus:ring-brand-500">{{ $rawContents }}</textarea>

            <label class="mt-4 block max-w-xs text-sm font-medium text-gray-700">Confirm your password
                <input type="password" name="password" autocomplete="current-password" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
            </label>
            @error('password')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
            <button type="submit" id="env-raw-replace-btn" class="mt-3 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-700">
                Replace file
            </button>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var tabs = document.querySelectorAll('.env-tab');
            var panels = {
                fields: document.getElementById('env-panel-fields'),
                raw: document.getElementById('env-panel-raw'),
            };

            function activateTab(name) {
                tabs.forEach(function (tab) {
                    var active = tab.dataset.envTab === name;
                    tab.classList.toggle('bg-brand-50', active);
                    tab.classList.toggle('text-brand-700', active);
                    tab.classList.toggle('text-gray-500', !active);
                });
                Object.keys(panels).forEach(function (key) {
                    panels[key].classList.toggle('hidden', key !== name);
                });
            }

            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () { activateTab(tab.dataset.envTab); });
            });
            activateTab('fields');

            document.querySelectorAll('.env-toggle-visibility').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var input = document.getElementById(btn.dataset.target);
                    var revealed = input.type === 'text';
                    input.type = revealed ? 'password' : 'text';
                    btn.textContent = revealed ? 'Show' : 'Hide';
                });
            });

            var envRawReplaceBtn = document.getElementById('env-raw-replace-btn');
            if (envRawReplaceBtn) {
                envRawReplaceBtn.addEventListener('click', async function (event) {
                    event.preventDefault();
                    if (!await MeetDialog.confirm('Replace the entire .env file with this content? A backup of the current file is kept, but a malformed save can break the app until restored.', { danger: true })) return;
                    envRawReplaceBtn.closest('form').submit();
                });
            }
        });
    </script>
</x-layouts.app>
