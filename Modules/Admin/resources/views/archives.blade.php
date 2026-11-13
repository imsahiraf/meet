<x-layouts.app title="Archives - Meet">
    @include('admin::partials.nav')

    <div class="mb-6 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
        <h2 class="mb-3 text-sm font-semibold text-gray-900">Archive settings</h2>
        <div class="grid grid-cols-2 gap-3">
            <label class="block text-sm font-medium text-gray-700">Recipient email<input type="email" id="archive-recipient" class="mt-1 w-full rounded-lg border-gray-300 shadow-sm sm:text-sm"></label>
            <label class="block text-sm font-medium text-gray-700">Schedule
                <select id="archive-frequency" class="mt-1 w-full rounded-lg border-gray-300 shadow-sm sm:text-sm">
                    <option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option><option value="manual_only">Manual only</option>
                </select>
            </label>
        </div>
        <button type="button" id="save-archive-settings" class="mt-3 rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">Save</button>
        <button type="button" id="trigger-archive" class="mt-3 ml-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Create archive now</button>
        <p id="archive-action-result" class="mt-2 text-xs"></p>
    </div>

    <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Created</th>
                        <th class="px-4 py-3">Scope</th>
                        <th class="px-4 py-3">Trigger</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Size</th>
                        <th class="px-4 py-3">Download</th>
                    </tr>
                </thead>
                <tbody id="archives-body" class="divide-y divide-gray-100"></tbody>
            </table>
            <p id="archives-empty" class="hidden px-4 py-10 text-center text-sm text-gray-400">No archives yet.</p>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            function loadSettings() {
                axios.get('/api/v1/admin/archives/settings').then(function (response) {
                    document.getElementById('archive-recipient').value = response.data.data.recipient_email;
                    document.getElementById('archive-frequency').value = response.data.data.schedule_frequency;
                });
            }

            document.getElementById('save-archive-settings').addEventListener('click', function () {
                axios.patch('/api/v1/admin/archives/settings', {
                    recipient_email: document.getElementById('archive-recipient').value,
                    schedule_frequency: document.getElementById('archive-frequency').value,
                }).then(async function () { await MeetDialog.alert('Archive settings saved.'); }).catch(async function () { await MeetDialog.alert('Could not save.'); });
            });

            function loadArchives() {
                axios.get('/api/v1/admin/archives').then(function (response) {
                    var archives = response.data.data;
                    var tbody = document.getElementById('archives-body');
                    tbody.innerHTML = '';
                    document.getElementById('archives-empty').classList.toggle('hidden', archives.length > 0);

                    archives.forEach(function (archive) {
                        var row = document.createElement('tr');
                        var downloadCell = ['completed', 'delivered'].indexOf(archive.status) !== -1
                            ? '<a href="/api/v1/admin/archives/' + archive.id + '/download" class="inline-flex items-center gap-1 text-brand-600 hover:underline">'
                                + '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11" /><path d="m7.5 11.5 4.5 4.5 4.5-4.5" /><path d="M5 19h14" /></svg>'
                                + '<span>Download</span></a>'
                            : '&mdash;';
                        row.innerHTML =
                            '<td class="px-4 py-3 text-gray-500">' + new Date(archive.created_at).toLocaleString() + '</td>' +
                            '<td class="px-4 py-3 text-gray-500">' + archive.scope.replace('_', ' ') + '</td>' +
                            '<td class="px-4 py-3 text-gray-500">' + archive.trigger + '</td>' +
                            '<td class="px-4 py-3 font-medium text-gray-900">' + archive.status + '</td>' +
                            '<td class="px-4 py-3 text-gray-500">' + (archive.size_bytes ? (archive.size_bytes / 1048576).toFixed(2) + ' MB' : '&mdash;') + '</td>' +
                            '<td class="px-4 py-3">' + downloadCell + '</td>';
                        tbody.appendChild(row);
                    });
                });
            }

            document.getElementById('trigger-archive').addEventListener('click', function () {
                var resultEl = document.getElementById('archive-action-result');
                axios.post('/api/v1/admin/archives').then(function () {
                    resultEl.textContent = 'Archive created and emailed.';
                    resultEl.className = 'mt-2 text-xs text-emerald-600';
                    loadArchives();
                }).catch(function (error) {
                    resultEl.textContent = (error.response && error.response.data && error.response.data.message) || 'Could not create archive.';
                    resultEl.className = 'mt-2 text-xs text-red-600';
                });
            });

            loadSettings();
            loadArchives();
            // Archive creation itself is synchronous (see
            // AdminArchiveController::store()), but this still polls
            // periodically to catch delivery-status updates and any
            // archive a *different* admin/session triggers or the
            // scheduled job creates in the background.
            setInterval(loadArchives, 3000);
        });
    </script>
</x-layouts.app>
