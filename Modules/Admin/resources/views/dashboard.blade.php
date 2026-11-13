<x-layouts.app title="Admin dashboard - Meet">
    @include('admin::partials.nav')

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <span class="text-sm text-gray-500">Download the report:</span>
        @foreach (['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'] as $f => $label)
            <a href="/api/v1/admin/reports/export?format={{ $f }}" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ $label }}</a>
        @endforeach
    </div>
    <div id="dashboard-warnings" class="mb-4 hidden space-y-2"></div>

    <div id="dashboard-stats" class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
        <p class="col-span-full text-sm text-gray-400">Loading&hellip;</p>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var labels = {
                total_users: 'Total Users', active_users: 'Active Users', online_users: 'Online Now',
                todays_registrations: "Today's Signups", active_chats: 'Active Chats', active_calls: 'Active Calls',
                active_meetings: 'Active Meetings', guest_sessions: 'Guest Sessions', storage_used_bytes: 'Storage Used',
                queue_failed_jobs: 'Failed Jobs',
            };

            axios.get('/api/v1/admin/dashboard').then(function (response) {
                var data = response.data.data;
                var grid = document.getElementById('dashboard-stats');
                grid.innerHTML = '';

                var warningsEl = document.getElementById('dashboard-warnings');
                if (data.warnings && data.warnings.length > 0) {
                    warningsEl.classList.remove('hidden');
                    warningsEl.innerHTML = data.warnings.map(function (message) {
                        return '<div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">&#9888; ' + message + '</div>';
                    }).join('');
                }

                Object.keys(labels).forEach(function (key) {
                    var value = data[key];
                    if (key === 'storage_used_bytes') {
                        value = (value / 1048576).toFixed(1) + ' MB';
                    }
                    var card = document.createElement('div');
                    card.className = 'rounded-2xl border border-gray-100 bg-white p-5 shadow-sm';
                    card.innerHTML = '<p class="text-sm text-gray-500">' + labels[key] + '</p><p class="mt-1 text-2xl font-semibold text-gray-900">' + value + '</p>';
                    grid.appendChild(card);
                });
            }).catch(function () {
                document.getElementById('dashboard-stats').innerHTML = '<p class="col-span-full text-sm text-red-500">Could not load dashboard stats.</p>';
            });
        });
    </script>
</x-layouts.app>
