<x-layouts.app title="Meetings - Meet">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold text-gray-900">Meetings</h1>
        <div class="flex gap-2">
            <a href="{{ route('meetings.calendar') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-gray-500 hover:bg-gray-100">Calendar</a>
            <a href="{{ route('meetings.create') }}" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Schedule meeting</a>
            <form method="POST" action="{{ route('meetings.instant') }}">
                @csrf
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">New meeting</button>
            </form>
        </div>
    </div>

    {{-- All/History used to be two separate pages cross-linked via plain
         header links - now one page with client-side tabs, mirroring
         Chat's All/Chats/Groups pattern. Calendar moved out to its own
         nav-level page (see Modules/Meetings/resources/views/calendar.blade.php). --}}
    <div class="mb-6 flex gap-1 rounded-xl border border-gray-100 bg-white p-1 shadow-sm" role="tablist">
        <button type="button" data-meeting-tab="all" class="meeting-tab flex-1 rounded-lg px-3 py-1.5 text-sm font-medium">All</button>
        <button type="button" data-meeting-tab="history" class="meeting-tab flex-1 rounded-lg px-3 py-1.5 text-sm font-medium">History</button>
    </div>

    <div id="meetings-panel-all" class="meeting-panel">
        <div class="mb-6 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
            <form method="GET" onsubmit="return goToMeetingCode(event)" class="flex gap-2">
                <input type="text" id="join-code-input" placeholder="Enter a meeting code to join"
                       class="flex-1 rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Join</button>
            </form>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-5 py-4">
                <h2 class="text-sm font-semibold text-gray-900">Upcoming meetings</h2>
            </div>
            <ul class="divide-y divide-gray-100">
                @forelse ($upcoming as $meeting)
                    <li class="flex items-center justify-between gap-4 px-5 py-4">
                        <div class="min-w-0">
                            <a href="{{ route('meetings.show', $meeting) }}" class="truncate text-sm font-medium text-gray-900 hover:text-brand-600">{{ $meeting->title }}</a>
                            <p class="text-xs text-gray-400">
                                {{ $meeting->scheduled_start?->format('M j, g:i A') }} &middot; Code {{ $meeting->meeting_code }} &middot; {{ $meeting->organizer?->full_name }}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            {{-- A meeting code alone can't be tapped/pasted
                                 into a chat message the way a real link can -
                                 same /j/{code} URL the room's own invite
                                 banner and guest-room already surface. --}}
                            <button type="button" class="meeting-copy-link-btn rounded-lg px-3 py-1.5 text-sm font-medium text-gray-500 hover:bg-gray-100" data-share-link="{{ url('/j/'.$meeting->meeting_code) }}">Copy link</button>
                            <a href="{{ route('meetings.show', $meeting) }}" class="rounded-lg px-3 py-1.5 text-sm font-medium text-brand-600 hover:bg-brand-50">Open</a>
                        </div>
                    </li>
                @empty
                    <li class="px-5 py-14 text-center text-sm text-gray-400">No upcoming meetings. Schedule one or start an instant meeting.</li>
                @endforelse
            </ul>
        </div>
    </div>

    <div id="meetings-panel-history" class="meeting-panel hidden">
        <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
            <ul class="divide-y divide-gray-100">
                @forelse ($history as $meeting)
                    <li class="flex items-center justify-between gap-4 px-5 py-4">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-gray-900">{{ $meeting->title }}</p>
                            <p class="text-xs text-gray-400">
                                {{ $meeting->organizer?->full_name }} &middot;
                                {{ ucfirst($meeting->status) }}
                                @if ($meeting->report)
                                    &middot; {{ gmdate('i:s', $meeting->report->duration_seconds) }} &middot; {{ $meeting->report->total_participants }} participants
                                @endif
                            </p>
                        </div>
                        <span class="shrink-0 text-xs text-gray-400">{{ $meeting->actual_start?->format('M j, g:i A') ?? $meeting->scheduled_start?->format('M j, g:i A') }}</span>
                    </li>
                @empty
                    <li class="px-5 py-14 text-center text-sm text-gray-400">No past meetings yet.</li>
                @endforelse
            </ul>
        </div>
    </div>

    <script>
        // Wrapped in DOMContentLoaded because Vite's script tag is
        // type="module" (deferred by spec) - window.axios doesn't
        // exist yet at the point the parser reaches this plain inline
        // script further down the page.
        document.addEventListener('DOMContentLoaded', function () {
            window.goToMeetingCode = function (event) {
                event.preventDefault();
                var code = document.getElementById('join-code-input').value.trim();
                if (!code) {
                    return false;
                }
                axios.get('/api/v1/meetings/lookup', { params: { code: code } }).then(function (response) {
                    window.location.href = '/meetings/' + response.data.data.id;
                }).catch(async function () {
                    await MeetDialog.alert('No meeting found with that code.');
                });
                return false;
            };

            document.querySelectorAll('.meeting-copy-link-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    navigator.clipboard.writeText(btn.dataset.shareLink).then(function () {
                        var original = btn.textContent;
                        btn.textContent = 'Copied!';
                        setTimeout(function () { btn.textContent = original; }, 2000);
                    });
                });
            });

            var tabs = document.querySelectorAll('.meeting-tab');
            var panels = {
                all: document.getElementById('meetings-panel-all'),
                history: document.getElementById('meetings-panel-history'),
            };

            function activateTab(name) {
                tabs.forEach(function (tab) {
                    var active = tab.dataset.meetingTab === name;
                    tab.classList.toggle('bg-brand-50', active);
                    tab.classList.toggle('text-brand-700', active);
                    tab.classList.toggle('text-gray-500', !active);
                });
                Object.keys(panels).forEach(function (key) {
                    panels[key].classList.toggle('hidden', key !== name);
                });
            }

            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () { activateTab(tab.dataset.meetingTab); });
            });

            activateTab('{{ $activeTab }}');
        });
    </script>
</x-layouts.app>
