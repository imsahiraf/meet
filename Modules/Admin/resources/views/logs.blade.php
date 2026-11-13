<x-layouts.app title="Audit logs - Meet">
    @include('admin::partials.nav')

    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm text-gray-500">Every admin change, with person, time and address.</p>
        <a href="/api/v1/admin/logs/audit.csv" class="rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Download CSV</a>
    </div>

    <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3">When</th>
                        <th class="px-4 py-3">Admin</th>
                        <th class="px-4 py-3">Module</th>
                        <th class="px-4 py-3">Action</th>
                        <th class="px-4 py-3">Record</th>
                        <th class="px-4 py-3">IP</th>
                    </tr>
                </thead>
                <tbody id="audit-log-body" class="divide-y divide-gray-100"></tbody>
            </table>
            <p id="audit-log-empty" class="hidden px-4 py-10 text-center text-sm text-gray-400">No audit log entries yet.</p>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            axios.get('/api/v1/admin/logs/audit').then(function (response) {
                var logs = response.data.data;
                var tbody = document.getElementById('audit-log-body');
                document.getElementById('audit-log-empty').classList.toggle('hidden', logs.length > 0);

                logs.forEach(function (log) {
                    var row = document.createElement('tr');
                    row.innerHTML =
                        '<td class="px-4 py-3 text-gray-500">' + new Date(log.created_at).toLocaleString() + '</td>' +
                        '<td class="px-4 py-3 text-gray-900">' + (log.user ? log.user.full_name : 'System') + '</td>' +
                        '<td class="px-4 py-3 text-gray-500">' + log.module + '</td>' +
                        '<td class="px-4 py-3 font-medium text-gray-900">' + log.action + '</td>' +
                        '<td class="px-4 py-3 text-gray-500">' + (log.record_type ? log.record_type.split('\\').pop() + ' #' + log.record_id : '&mdash;') + '</td>' +
                        '<td class="px-4 py-3 text-gray-400">' + (log.ip_address || '') + '</td>';
                    tbody.appendChild(row);
                });
            }).catch(function () {});
        });
    </script>
</x-layouts.app>
