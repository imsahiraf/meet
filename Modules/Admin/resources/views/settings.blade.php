<x-layouts.app title="Settings - Meet">
    @include('admin::partials.nav')

    <div class="flex gap-1 border-b border-gray-100 text-sm">
        <button type="button" data-tab="general" class="settings-tab border-b-2 border-brand-600 px-3 py-2 font-medium text-brand-700">General</button>
        <button type="button" data-tab="branding" class="settings-tab border-b-2 border-transparent px-3 py-2 font-medium text-gray-500">Branding</button>
        <button type="button" data-tab="smtp" class="settings-tab border-b-2 border-transparent px-3 py-2 font-medium text-gray-500">SMTP</button>
        <button type="button" data-tab="features" class="settings-tab border-b-2 border-transparent px-3 py-2 font-medium text-gray-500">Feature Flags</button>
        <button type="button" data-tab="maintenance" class="settings-tab border-b-2 border-transparent px-3 py-2 font-medium text-gray-500">Maintenance</button>
    </div>

    <div class="mt-6 max-w-2xl">
        <div id="panel-general" class="settings-panel space-y-4">
            <div id="general-fields" class="space-y-3"></div>
            <button type="button" id="save-general" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save general settings</button>
        </div>

        <div id="panel-branding" class="settings-panel hidden space-y-3">
            <label class="block text-sm font-medium text-gray-700">App name<input type="text" id="branding-app-name" class="mt-1 w-full rounded-lg border-gray-300 shadow-sm sm:text-sm"></label>
            <label class="block text-sm font-medium text-gray-700">Company name<input type="text" id="branding-company-name" class="mt-1 w-full rounded-lg border-gray-300 shadow-sm sm:text-sm"></label>
            <label class="block text-sm font-medium text-gray-700">Theme color<input type="color" id="branding-theme-color" class="mt-1 h-9 w-16"></label>
            <label class="block text-sm font-medium text-gray-700">Colour theme for everybody (until a person picks their own in Preferences)
                <select id="branding-default-accent" class="mt-1 w-full rounded-lg border-gray-300 shadow-sm sm:text-sm"><option value="">Indigo (default)</option><option value="brand">Our theme colour (above)</option>@foreach (\Modules\User\Models\UserPreference::ACCENTS as $k => [$l]) <option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
            <label class="block text-sm font-medium text-gray-700">Footer text<textarea id="branding-footer" rows="2" class="mt-1 w-full rounded-lg border-gray-300 shadow-sm sm:text-sm"></textarea></label>
            <button type="button" id="save-branding" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save branding</button>
        </div>

        <div id="panel-smtp" class="settings-panel hidden space-y-3">
            <label class="block text-sm font-medium text-gray-700">Host<input type="text" id="smtp-host" class="mt-1 w-full rounded-lg border-gray-300 shadow-sm sm:text-sm"></label>
            <label class="block text-sm font-medium text-gray-700">Port<input type="number" id="smtp-port" class="mt-1 w-full rounded-lg border-gray-300 shadow-sm sm:text-sm"></label>
            <label class="block text-sm font-medium text-gray-700">Encryption
                <select id="smtp-encryption" class="mt-1 w-full rounded-lg border-gray-300 shadow-sm sm:text-sm">
                    <option value="tls">TLS</option><option value="ssl">SSL</option><option value="none">None</option>
                </select>
            </label>
            <label class="block text-sm font-medium text-gray-700">Username<input type="text" id="smtp-username" class="mt-1 w-full rounded-lg border-gray-300 shadow-sm sm:text-sm"></label>
            <label class="block text-sm font-medium text-gray-700">Password <span id="smtp-password-hint" class="text-xs text-gray-400"></span><input type="password" id="smtp-password" placeholder="Leave blank to keep current" class="mt-1 w-full rounded-lg border-gray-300 shadow-sm sm:text-sm"></label>
            <label class="block text-sm font-medium text-gray-700">From email<input type="email" id="smtp-from-email" class="mt-1 w-full rounded-lg border-gray-300 shadow-sm sm:text-sm"></label>
            <div class="flex gap-2">
                <button type="button" id="save-smtp" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save SMTP</button>
                <button type="button" id="test-smtp" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Send test email</button>
            </div>
            <p id="smtp-test-result" class="text-xs"></p>
        </div>

        <div id="panel-features" class="settings-panel hidden space-y-2"></div>

        <div id="panel-maintenance" class="settings-panel hidden space-y-3">
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" id="maintenance-enabled" class="rounded border-gray-300 text-brand-600">
                Enable maintenance mode
            </label>
            <textarea id="maintenance-message" rows="2" placeholder="Maintenance message shown to users" class="w-full rounded-lg border-gray-300 shadow-sm sm:text-sm"></textarea>
            <button type="button" id="save-maintenance" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save</button>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.settings-tab').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    document.querySelectorAll('.settings-tab').forEach(function (t) {
                        t.classList.remove('border-brand-600', 'text-brand-700');
                        t.classList.add('border-transparent', 'text-gray-500');
                    });
                    tab.classList.add('border-brand-600', 'text-brand-700');
                    tab.classList.remove('border-transparent', 'text-gray-500');
                    document.querySelectorAll('.settings-panel').forEach(function (p) { p.classList.add('hidden'); });
                    document.getElementById('panel-' + tab.dataset.tab).classList.remove('hidden');
                });
            });

            var GENERAL_LABELS = {
                session_timeout_minutes: 'Session timeout (minutes)',
                login_max_attempts: 'Max login attempts',
                login_lockout_minutes: 'Lockout duration (minutes)',
                password_min_length: 'Minimum password length',
                api_token_expiry_days: 'API token expiry (days)',
                file_retention_policy: 'File retention (days)',
                file_upload_max_mb: 'Max upload size (MB)',
                delete_for_everyone_window_minutes: 'Delete-for-everyone window (minutes)',
            };

            function loadGeneral() {
                axios.get('/api/v1/admin/settings/general').then(function (response) {
                    var data = response.data.data;
                    var container = document.getElementById('general-fields');
                    container.innerHTML = '';
                    Object.keys(GENERAL_LABELS).forEach(function (key) {
                        var wrap = document.createElement('label');
                        wrap.className = 'block text-sm font-medium text-gray-700';
                        wrap.innerHTML = GENERAL_LABELS[key] + '<input type="number" data-key="' + key + '" value="' + (data[key] ?? '') + '" class="mt-1 w-full rounded-lg border-gray-300 shadow-sm sm:text-sm">';
                        container.appendChild(wrap);
                    });
                });
            }

            document.getElementById('save-general').addEventListener('click', function () {
                var settings = {};
                document.querySelectorAll('#general-fields input').forEach(function (input) {
                    settings[input.dataset.key] = input.value;
                });
                axios.patch('/api/v1/admin/settings/general', { settings: settings }).then(async function () {
                    await MeetDialog.alert('Settings saved.');
                }).catch(async function () { await MeetDialog.alert('Could not save settings.'); });
            });

            function loadBranding() {
                axios.get('/api/v1/admin/settings/branding').then(function (response) {
                    var data = response.data.data;
                    document.getElementById('branding-app-name').value = data.app_name || '';
                    document.getElementById('branding-company-name').value = data.company_name || '';
                    document.getElementById('branding-theme-color').value = data.theme_color || '#4f46e5';
                    document.getElementById('branding-footer').value = data.footer_text || '';
                    document.getElementById('branding-default-accent').value = data.default_accent || '';
                });
            }

            document.getElementById('save-branding').addEventListener('click', function () {
                axios.patch('/api/v1/admin/settings/branding', {
                    app_name: document.getElementById('branding-app-name').value,
                    company_name: document.getElementById('branding-company-name').value,
                    theme_color: document.getElementById('branding-theme-color').value,
                    footer_text: document.getElementById('branding-footer').value,
                    default_accent: document.getElementById('branding-default-accent').value || null,
                }).then(async function () { await MeetDialog.alert('Branding saved.'); }).catch(async function () { await MeetDialog.alert('Could not save branding.'); });
            });

            function loadSmtp() {
                axios.get('/api/v1/admin/settings/smtp').then(function (response) {
                    var data = response.data.data;
                    document.getElementById('smtp-host').value = data.host || '';
                    document.getElementById('smtp-port').value = data.port || '';
                    document.getElementById('smtp-encryption').value = data.encryption || 'tls';
                    document.getElementById('smtp-username').value = data.username || '';
                    document.getElementById('smtp-from-email').value = data.from_email || '';
                    document.getElementById('smtp-password-hint').textContent = data.password_set ? '(currently set)' : '(not set)';
                });
            }

            document.getElementById('save-smtp').addEventListener('click', function () {
                var payload = {
                    host: document.getElementById('smtp-host').value,
                    port: document.getElementById('smtp-port').value || null,
                    encryption: document.getElementById('smtp-encryption').value,
                    username: document.getElementById('smtp-username').value,
                    from_email: document.getElementById('smtp-from-email').value,
                };
                var password = document.getElementById('smtp-password').value;
                if (password) payload.password = password;

                axios.patch('/api/v1/admin/settings/smtp', payload).then(async function () {
                    await MeetDialog.alert('SMTP settings saved.');
                    loadSmtp();
                }).catch(async function () { await MeetDialog.alert('Could not save SMTP settings.'); });
            });

            document.getElementById('test-smtp').addEventListener('click', function () {
                var resultEl = document.getElementById('smtp-test-result');
                resultEl.textContent = 'Sending&hellip;';
                axios.post('/api/v1/admin/settings/smtp/test').then(function (response) {
                    resultEl.textContent = response.data.message;
                    resultEl.className = 'text-xs text-emerald-600';
                }).catch(function (error) {
                    resultEl.textContent = (error.response && error.response.data && error.response.data.message) || 'Test failed.';
                    resultEl.className = 'text-xs text-red-600';
                });
            });

            function loadFeatures() {
                axios.get('/api/v1/admin/settings/features').then(function (response) {
                    var container = document.getElementById('panel-features');
                    container.innerHTML = '';
                    response.data.data.forEach(function (flag) {
                        var row = document.createElement('label');
                        row.className = 'flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2 text-sm';
                        row.innerHTML = '<span>' + flag.label + '</span>';
                        var toggle = document.createElement('input');
                        toggle.type = 'checkbox';
                        toggle.checked = flag.is_enabled;
                        toggle.className = 'rounded border-gray-300 text-brand-600';
                        toggle.addEventListener('change', function () {
                            axios.patch('/api/v1/admin/settings/features/' + flag.feature_key, { is_enabled: toggle.checked }).catch(async function () {
                                toggle.checked = !toggle.checked;
                                await MeetDialog.alert('Could not update feature flag.');
                            });
                        });
                        row.appendChild(toggle);
                        container.appendChild(row);
                    });
                });
            }

            function loadMaintenance() {
                axios.get('/api/v1/admin/settings/general').then(function (response) {
                    document.getElementById('maintenance-enabled').checked = !!response.data.data.maintenance_mode_enabled;
                    document.getElementById('maintenance-message').value = response.data.data.maintenance_message || '';
                });
            }

            document.getElementById('save-maintenance').addEventListener('click', function () {
                axios.post('/api/v1/admin/system/maintenance', {
                    enabled: document.getElementById('maintenance-enabled').checked,
                    message: document.getElementById('maintenance-message').value,
                }).then(async function () { await MeetDialog.alert('Maintenance settings saved.'); }).catch(async function () { await MeetDialog.alert('Could not save.'); });
            });

            loadGeneral();
            loadBranding();
            loadSmtp();
            loadFeatures();
            loadMaintenance();
        });
    </script>
</x-layouts.app>
