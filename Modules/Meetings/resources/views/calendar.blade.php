<x-layouts.app title="Calendar - Meet">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold text-gray-900">Calendar</h1>
        <a href="{{ route('meetings.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-gray-500 hover:bg-gray-100">Back to meetings</a>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <button type="button" id="cal-today" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-600 hover:bg-gray-50">Today</button>
                <button type="button" id="cal-prev" class="rounded-full p-1.5 text-gray-500 hover:bg-gray-100" aria-label="Previous">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5"><path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 010 1.06L9.06 10l3.73 3.71a.75.75 0 11-1.06 1.06l-4.25-4.25a.75.75 0 010-1.06l4.25-4.25a.75.75 0 011.06 0z" clip-rule="evenodd" /></svg>
                </button>
                <button type="button" id="cal-next" class="rounded-full p-1.5 text-gray-500 hover:bg-gray-100" aria-label="Next">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 010-1.06L10.94 10 7.21 6.29a.75.75 0 111.06-1.06l4.25 4.25a.75.75 0 010 1.06l-4.25 4.25a.75.75 0 01-1.06 0z" clip-rule="evenodd" /></svg>
                </button>
            </div>
            <h2 id="cal-range-label" class="text-lg font-normal text-gray-700"></h2>
            <div class="flex items-center gap-2">
                <div class="flex gap-1 rounded-lg border border-gray-200 p-0.5" role="tablist">
                    <button type="button" data-cal-view="month" class="cal-view-tab rounded-md px-3 py-1 text-sm font-medium">Month</button>
                    <button type="button" data-cal-view="week" class="cal-view-tab rounded-md px-3 py-1 text-sm font-medium">Week</button>
                    <button type="button" data-cal-view="day" class="cal-view-tab rounded-md px-3 py-1 text-sm font-medium">Day</button>
                </div>
                <button type="button" id="cal-add-event-btn" class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-700">+ Add event</button>
            </div>
        </div>

        {{-- Month view --}}
        <div id="cal-month-view">
            <div class="grid grid-cols-7 border-b border-gray-200 text-center text-xs font-medium uppercase tracking-wide text-gray-500">
                <div class="py-2">Sun</div>
                <div class="py-2">Mon</div>
                <div class="py-2">Tue</div>
                <div class="py-2">Wed</div>
                <div class="py-2">Thu</div>
                <div class="py-2">Fri</div>
                <div class="py-2">Sat</div>
            </div>
            <div id="cal-grid" class="grid grid-cols-7 border-l border-t border-gray-200"></div>
        </div>

        {{-- Week view --}}
        <div id="cal-week-view" class="hidden">
            <div id="cal-week-header" class="grid border-b border-gray-200 text-center text-xs font-medium text-gray-500" style="grid-template-columns: 56px repeat(7, minmax(0, 1fr));"></div>
            <div class="overflow-y-auto" style="height: 32rem;">
                <div id="cal-week-body" class="relative grid" style="grid-template-columns: 56px repeat(7, minmax(0, 1fr)); height: 1152px;"></div>
            </div>
        </div>

        {{-- Day view --}}
        <div id="cal-day-view" class="hidden">
            <div id="cal-day-header" class="border-b border-gray-200 py-2 text-center text-sm font-medium text-gray-700"></div>
            <div class="overflow-y-auto" style="height: 32rem;">
                <div id="cal-day-body" class="relative grid" style="grid-template-columns: 56px minmax(0, 1fr); height: 1152px;"></div>
            </div>
        </div>

        <p id="cal-empty-note" class="mt-4 hidden text-center text-sm text-gray-400">No scheduled meetings to show yet.</p>
    </div>

    {{-- Day-detail popover - GCal-style "click to see everything on this
         day" instead of a plainly truncated "+N more" text. --}}
    <div id="cal-day-popover" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">
        <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-xl">
            <div class="mb-3 flex items-center justify-between">
                <h3 id="cal-day-popover-title" class="text-sm font-semibold text-gray-900"></h3>
                <button type="button" id="cal-day-popover-close" class="rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                </button>
            </div>
            <div id="cal-day-popover-list" class="max-h-80 space-y-1.5 overflow-y-auto"></div>
        </div>
    </div>

    {{-- Event detail popup - clicking any event (chip, timed block, or a
         row inside the day popover above) shows this first instead of
         navigating straight into joining a meeting. Only from here does
         "Join meeting" actually navigate. --}}
    <div id="cal-event-detail-modal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-black/50 p-4">
        <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-xl">
            <div class="mb-2 flex items-start justify-between gap-2">
                <span class="inline-flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-gray-400">
                    <span id="cal-event-detail-dot" class="h-2.5 w-2.5 rounded-full"></span>
                    <span id="cal-event-detail-type-label"></span>
                </span>
                <button type="button" id="cal-event-detail-close" class="rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                </button>
            </div>
            <h3 id="cal-event-detail-title" class="text-base font-semibold text-gray-900"></h3>
            <p id="cal-event-detail-time" class="mt-1 text-sm text-gray-500"></p>
            <p id="cal-event-detail-description" class="mt-3 hidden whitespace-pre-line text-sm text-gray-600"></p>
            <div class="mt-5 flex items-center justify-end gap-2">
                <button type="button" id="cal-event-detail-delete" class="hidden rounded-lg px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50">Delete</button>
                <button type="button" id="cal-event-detail-copy-link" class="hidden rounded-lg px-3 py-2 text-sm font-medium text-gray-500 hover:bg-gray-100">Copy link</button>
                <a id="cal-event-detail-join" class="hidden rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700" href="#">Join meeting</a>
            </div>
        </div>
    </div>

    {{-- Add a personal/reminder/holiday/task entry - meeting events
         are only ever created from the Meetings scheduling form, not
         here, since only that flow sets up invitations/RSVPs. Styled
         to match that same "Schedule a meeting" form/card as closely
         as a same-page popup reasonably can (rounded-2xl card,
         identical field spacing/labels, one bulleted error box). --}}
    <div id="cal-add-event-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">
        <div class="w-full max-w-md rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
            <div class="mb-5 flex items-center justify-between">
                <h1 class="text-lg font-semibold text-gray-900">Add a calendar entry</h1>
                <button type="button" id="cal-add-event-close" class="rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                </button>
            </div>
            <div class="space-y-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Title</label>
                    <input type="text" id="cal-add-title" maxlength="255" class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Description</label>
                    <textarea id="cal-add-description" rows="3" class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm"></textarea>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Start</label>
                        <input type="datetime-local" id="cal-add-start" class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">End</label>
                        <input type="datetime-local" id="cal-add-end" class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Type</label>
                    <div class="mt-2 space-y-2">
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="radio" name="cal-add-type" value="personal" checked class="border-gray-300 text-brand-600">
                            Personal
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="radio" name="cal-add-type" value="reminder" class="border-gray-300 text-brand-600">
                            Reminder
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="radio" name="cal-add-type" value="task" class="border-gray-300 text-brand-600">
                            Task
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="radio" name="cal-add-type" value="holiday" class="border-gray-300 text-brand-600">
                            Holiday
                        </label>
                    </div>
                </div>

                <div id="cal-add-errors" class="hidden rounded-lg border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-700">
                    <ul id="cal-add-errors-list" class="list-disc space-y-1 pl-4"></ul>
                </div>

                <button type="button" id="cal-add-save" class="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
                    Add to calendar
                </button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var PX_PER_HOUR = 48;
            var DAY_HEIGHT = PX_PER_HOUR * 24;
            var WEEKDAY_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            var MIN_DURATION_MINUTES = 15;

            // Visually distinguishes event_type - otherwise every chip/
            // block looked identical regardless of what kind of entry
            // it was, which made non-meeting entries (added below)
            // indistinguishable from real meetings at a glance.
            var EVENT_TYPE_COLORS = {
                meeting: { dot: 'bg-brand-600', chip: 'bg-brand-50 text-brand-700 hover:bg-brand-100', block: 'bg-brand-100 text-brand-800 hover:bg-brand-200' },
                reminder: { dot: 'bg-amber-500', chip: 'bg-amber-50 text-amber-700 hover:bg-amber-100', block: 'bg-amber-100 text-amber-800 hover:bg-amber-200' },
                personal: { dot: 'bg-purple-500', chip: 'bg-purple-50 text-purple-700 hover:bg-purple-100', block: 'bg-purple-100 text-purple-800 hover:bg-purple-200' },
                holiday: { dot: 'bg-emerald-500', chip: 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100', block: 'bg-emerald-100 text-emerald-800 hover:bg-emerald-200' },
                task: { dot: 'bg-gray-500', chip: 'bg-gray-100 text-gray-700 hover:bg-gray-200', block: 'bg-gray-200 text-gray-800 hover:bg-gray-300' },
            };
            function colorsFor(event) {
                return EVENT_TYPE_COLORS[event.event_type] || EVENT_TYPE_COLORS.meeting;
            }

            var monthView = document.getElementById('cal-month-view');
            var weekView = document.getElementById('cal-week-view');
            var dayView = document.getElementById('cal-day-view');
            var grid = document.getElementById('cal-grid');
            var weekHeader = document.getElementById('cal-week-header');
            var weekBody = document.getElementById('cal-week-body');
            var dayHeader = document.getElementById('cal-day-header');
            var dayBody = document.getElementById('cal-day-body');
            var rangeLabel = document.getElementById('cal-range-label');
            var emptyNote = document.getElementById('cal-empty-note');
            var dayPopover = document.getElementById('cal-day-popover');
            var dayPopoverTitle = document.getElementById('cal-day-popover-title');
            var dayPopoverList = document.getElementById('cal-day-popover-list');

            var today = new Date();
            today.setHours(0, 0, 0, 0);
            var viewDate = new Date(today.getTime());
            var currentView = 'month';
            var eventsById = {};
            var draggingEventId = null;
            var loadRequestToken = 0;

            function dateKey(d) {
                return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
            }

            function startOfWeek(d) {
                var copy = new Date(d.getFullYear(), d.getMonth(), d.getDate());
                copy.setDate(copy.getDate() - copy.getDay());
                return copy;
            }

            function formatTime(iso) {
                return new Date(iso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
            }

            function openDayPopover(cellDate, dayEvents) {
                dayPopoverTitle.textContent = cellDate.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
                dayPopoverList.innerHTML = '';
                dayEvents.forEach(function (event) {
                    var row = document.createElement('div');
                    row.className = 'flex items-center gap-2 rounded-lg px-2 py-2 text-sm hover:bg-gray-50';
                    var link = document.createElement('button');
                    link.type = 'button';
                    link.className = 'flex flex-1 items-center gap-2 overflow-hidden text-left';
                    link.addEventListener('click', function () { openEventDetail(event); });
                    var dot = document.createElement('span');
                    dot.className = 'h-2 w-2 shrink-0 rounded-full ' + colorsFor(event).dot;
                    var time = document.createElement('span');
                    time.className = 'shrink-0 text-xs font-medium text-gray-500';
                    time.textContent = formatTime(event.start_at);
                    var title = document.createElement('span');
                    title.className = 'truncate font-medium text-gray-900';
                    title.textContent = event.title;
                    link.appendChild(dot);
                    link.appendChild(time);
                    link.appendChild(title);
                    row.appendChild(link);

                    if (!event.meeting_id) {
                        var del = document.createElement('button');
                        del.type = 'button';
                        del.className = 'shrink-0 text-xs font-medium text-red-500 hover:text-red-700';
                        del.textContent = 'Delete';
                        del.addEventListener('click', async function () {
                            if (!await MeetDialog.confirm('Delete this entry?', { danger: true })) return;
                            axios.delete('/api/v1/calendar/events/' + event.id).then(function () {
                                closeDayPopover();
                                loadAndRender();
                            });
                        });
                        row.appendChild(del);
                    }

                    dayPopoverList.appendChild(row);
                });
                dayPopover.classList.remove('hidden');
                dayPopover.classList.add('flex');
            }

            function closeDayPopover() {
                dayPopover.classList.add('hidden');
                dayPopover.classList.remove('flex');
            }

            document.getElementById('cal-day-popover-close').addEventListener('click', closeDayPopover);
            dayPopover.addEventListener('click', function (event) {
                if (event.target === dayPopover) closeDayPopover();
            });

            // Event detail popup - every event click (month chip, week/day
            // timed block, day-popover row) opens this first rather than
            // navigating straight into the meeting/join flow. Only its
            // own "Join meeting" button actually navigates.
            var eventDetailModal = document.getElementById('cal-event-detail-modal');
            var eventDetailDot = document.getElementById('cal-event-detail-dot');
            var eventDetailTypeLabel = document.getElementById('cal-event-detail-type-label');
            var eventDetailTitle = document.getElementById('cal-event-detail-title');
            var eventDetailTime = document.getElementById('cal-event-detail-time');
            var eventDetailDescription = document.getElementById('cal-event-detail-description');
            var eventDetailDelete = document.getElementById('cal-event-detail-delete');
            var eventDetailJoin = document.getElementById('cal-event-detail-join');
            var eventDetailCopyLink = document.getElementById('cal-event-detail-copy-link');

            function formatEventRange(event) {
                var start = new Date(event.start_at);
                var end = new Date(event.end_at);
                var sameDay = start.toDateString() === end.toDateString();
                var dateLabel = start.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
                return sameDay
                    ? dateLabel + ' · ' + formatTime(event.start_at) + ' - ' + formatTime(event.end_at)
                    : dateLabel + ' ' + formatTime(event.start_at) + ' - ' + end.toLocaleDateString(undefined, { month: 'long', day: 'numeric' }) + ' ' + formatTime(event.end_at);
            }

            function openEventDetail(event) {
                eventDetailDot.className = 'h-2.5 w-2.5 rounded-full ' + colorsFor(event).dot;
                eventDetailTypeLabel.textContent = event.event_type || 'meeting';
                eventDetailTitle.textContent = event.title;
                eventDetailTime.textContent = formatEventRange(event);

                if (event.description) {
                    eventDetailDescription.textContent = event.description;
                    eventDetailDescription.classList.remove('hidden');
                } else {
                    eventDetailDescription.classList.add('hidden');
                }

                if (event.meeting_id) {
                    eventDetailJoin.href = '/meetings/' + event.meeting_id;
                    eventDetailJoin.classList.remove('hidden');
                    eventDetailDelete.classList.add('hidden');

                    if (event.meeting_code) {
                        eventDetailCopyLink.classList.remove('hidden');
                        eventDetailCopyLink.onclick = function () {
                            navigator.clipboard.writeText(window.location.origin + '/j/' + event.meeting_code).then(function () {
                                eventDetailCopyLink.textContent = 'Copied!';
                                setTimeout(function () { eventDetailCopyLink.textContent = 'Copy link'; }, 2000);
                            });
                        };
                    } else {
                        eventDetailCopyLink.classList.add('hidden');
                    }
                } else {
                    eventDetailJoin.classList.add('hidden');
                    eventDetailCopyLink.classList.add('hidden');
                    eventDetailDelete.classList.remove('hidden');
                    eventDetailDelete.onclick = async function () {
                        if (!await MeetDialog.confirm('Delete this entry?', { danger: true })) return;
                        axios.delete('/api/v1/calendar/events/' + event.id).then(function () {
                            closeEventDetail();
                            loadAndRender();
                        });
                    };
                }

                eventDetailModal.classList.remove('hidden');
                eventDetailModal.classList.add('flex');
            }

            function closeEventDetail() {
                eventDetailModal.classList.add('hidden');
                eventDetailModal.classList.remove('flex');
            }

            document.getElementById('cal-event-detail-close').addEventListener('click', closeEventDetail);
            eventDetailModal.addEventListener('click', function (event) {
                if (event.target === eventDetailModal) closeEventDetail();
            });

            // Drag-to-reschedule - dragged event id travels via
            // dataTransfer, the full event object is looked up from
            // eventsById on drop (duration must be preserved: dropping
            // just sets a new start, end = start + original duration).
            function reschedule(eventId, newStart) {
                var event = eventsById[eventId];
                if (!event) return;

                var durationMs = new Date(event.end_at).getTime() - new Date(event.start_at).getTime();
                var newEnd = new Date(newStart.getTime() + durationMs);

                axios.patch('/api/v1/calendar/events/' + eventId + '/reschedule', {
                    start_at: newStart.toISOString(),
                    end_at: newEnd.toISOString(),
                }).then(function () {
                    loadAndRender();
                }).catch(async function (error) {
                    await MeetDialog.alert(error.response && error.response.status === 403
                        ? 'Only the organizer can reschedule this event.'
                        : 'Could not reschedule this event.');
                });
            }

            // Drag-to-resize (Week/Day only) - start_at is left exactly
            // as it was, only the duration/end_at changes. Reuses the
            // same reschedule endpoint reschedule() above calls, just
            // with a different start/end pairing than a move produces.
            function resizeEnd(eventId, newEnd) {
                var event = eventsById[eventId];
                if (!event) return;

                axios.patch('/api/v1/calendar/events/' + eventId + '/reschedule', {
                    start_at: event.start_at,
                    end_at: newEnd.toISOString(),
                }).then(function () {
                    loadAndRender();
                }).catch(async function (error) {
                    await MeetDialog.alert(error.response && error.response.status === 403
                        ? 'Only the organizer can resize this event.'
                        : 'Could not resize this event.');
                });
            }

            function makeChip(event) {
                var chip = document.createElement('button');
                chip.type = 'button';
                chip.draggable = true;
                chip.className = 'mb-1 flex w-full items-center gap-1 truncate rounded px-1.5 py-0.5 text-left text-[11px] font-medium ' + colorsFor(event).chip;
                var dot = document.createElement('span');
                dot.className = 'h-1.5 w-1.5 shrink-0 rounded-full ' + colorsFor(event).dot;
                var time = document.createElement('span');
                time.className = 'shrink-0';
                time.textContent = formatTime(event.start_at);
                var title = document.createElement('span');
                title.className = 'truncate';
                title.textContent = event.title;
                chip.appendChild(dot);
                chip.appendChild(time);
                chip.appendChild(title);
                chip.addEventListener('click', function (e) {
                    // Shows details first (see openEventDetail) - never
                    // navigates straight into joining, and must not also
                    // trigger the day cell's own click-to-open-popover.
                    e.stopPropagation();
                    openEventDetail(event);
                });
                chip.addEventListener('dragstart', function (e) {
                    draggingEventId = event.id;
                    e.dataTransfer.setData('text/plain', String(event.id));
                    e.dataTransfer.effectAllowed = 'move';
                });
                return chip;
            }

            // ---- Month view ----
            function renderMonth(eventsByDate) {
                var year = viewDate.getFullYear();
                var month = viewDate.getMonth();
                rangeLabel.textContent = viewDate.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });

                var firstOfMonth = new Date(year, month, 1);
                var startOffset = firstOfMonth.getDay();
                var daysInMonth = new Date(year, month + 1, 0).getDate();
                var totalCells = Math.ceil((startOffset + daysInMonth) / 7) * 7;

                grid.innerHTML = '';
                var anyEvent = Object.keys(eventsByDate).length > 0;

                // Mobile cells are both narrower and shorter than desktop
                // - showing the same 3 chips there just forced the whole
                // grid taller than the viewport (making the page itself
                // scroll to see the rest of the month). Fewer chips per
                // cell on mobile, with the day cell itself now tappable
                // (see below) to see everything that day has - not just
                // whatever the "+N more" overflow already covered.
                var isMobileViewport = window.matchMedia('(max-width: 639px)').matches;
                var maxChipsPerCell = isMobileViewport ? 1 : 3;

                for (var i = 0; i < totalCells; i++) {
                    var dayNum = i - startOffset + 1;
                    var cellDate = new Date(year, month, dayNum);
                    var inMonth = dayNum >= 1 && dayNum <= daysInMonth;
                    var isToday = inMonth && cellDate.getTime() === today.getTime();
                    var key = dateKey(cellDate);
                    var dayEvents = inMonth ? (eventsByDate[key] || []) : [];

                    var cell = document.createElement('div');
                    // No min-height here - the grid's own row track
                    // height (set by fitMonthGridToViewport() below) is
                    // what actually sizes every cell, so the whole
                    // month always fits in the space available without
                    // ever needing the page itself to scroll to see
                    // the rest of the weeks.
                    cell.className = 'flex flex-col overflow-hidden border-b border-r border-gray-200 bg-white p-1.5 ' +
                        (inMonth ? '' : 'bg-gray-50/60 text-gray-300') + (inMonth && dayEvents.length > 0 ? ' cursor-pointer hover:bg-gray-50' : '');
                    cell.addEventListener('dragover', function (e) { e.preventDefault(); });
                    cell.addEventListener('drop', (function (cellDate) {
                        return function (e) {
                            e.preventDefault();
                            var id = e.dataTransfer.getData('text/plain');
                            var event = eventsById[id];
                            if (!event) return;
                            var originalStart = new Date(event.start_at);
                            var newStart = new Date(cellDate.getFullYear(), cellDate.getMonth(), cellDate.getDate(), originalStart.getHours(), originalStart.getMinutes());
                            reschedule(id, newStart);
                        };
                    })(cellDate));

                    // Tapping the day cell itself (not a chip or the
                    // "+more" button, both of which stopPropagation/have
                    // their own handler) opens the same day-detail
                    // popover the "+N more" link already used - the
                    // primary way to see a day's events on mobile now,
                    // rather than needing the grid to be tall enough to
                    // show them all inline.
                    if (inMonth && dayEvents.length > 0) {
                        cell.addEventListener('click', (function (cellDate, dayEvents) {
                            return function () { openDayPopover(cellDate, dayEvents); };
                        })(cellDate, dayEvents));
                    }

                    var numEl = document.createElement('div');
                    numEl.className = 'mb-1 inline-flex h-6 w-6 items-center justify-center rounded-full text-xs font-medium ' +
                        (isToday ? 'bg-brand-600 text-white' : inMonth ? 'text-gray-700' : 'text-gray-300');
                    numEl.textContent = inMonth ? dayNum : '';
                    cell.appendChild(numEl);

                    dayEvents.slice(0, maxChipsPerCell).forEach(function (event) {
                        cell.appendChild(makeChip(event));
                    });

                    if (dayEvents.length > maxChipsPerCell) {
                        var more = document.createElement('button');
                        more.type = 'button';
                        more.className = 'text-[11px] font-medium text-gray-500 hover:text-brand-600';
                        more.textContent = '+' + (dayEvents.length - maxChipsPerCell) + ' more';
                        more.addEventListener('click', (function (cellDate, dayEvents) {
                            return function (e) {
                                e.stopPropagation();
                                openDayPopover(cellDate, dayEvents);
                            };
                        })(cellDate, dayEvents));
                        cell.appendChild(more);
                    }

                    grid.appendChild(cell);
                }

                // Every week row shares the grid's own fitted height
                // equally (5-row vs 6-row months both still fit) -
                // fitMonthGridToViewport() is what actually sets that
                // total height based on real available space.
                grid.style.gridTemplateRows = 'repeat(' + (totalCells / 7) + ', minmax(0, 1fr))';

                // Must run BEFORE fitMonthGridToViewport() below - the
                // "No scheduled meetings yet" note only takes up space
                // once its own hidden class has already been decided,
                // and the fit calculation needs to know whether that
                // trailing element will be there to reserve room for.
                emptyNote.classList.toggle('hidden', anyEvent);

                fitMonthGridToViewport();
            }

            // GCal's own month view never scrolls - it compresses row
            // height to whatever space is actually available instead.
            // A static min-height per cell (the old approach) forced a
            // 6-row month taller than most viewports, making the whole
            // page scroll just to see the last week. Measured against
            // the real viewport (not a fixed guess) so it keeps
            // working across every device size and both the fixed
            // mobile bottom nav and the desktop static header.
            function fitMonthGridToViewport() {
                if (currentView !== 'month' || monthView.classList.contains('hidden')) {
                    return;
                }

                var top = grid.getBoundingClientRect().top;

                // <main>'s own bottom padding already reserves more
                // room than the fixed bottom nav actually needs (it's
                // sized generously for every OTHER page's normal
                // scrolling content, not just this one) - counting the
                // nav's height on top of it as well would reserve that
                // clearance twice. The calendar card's own p-5 also
                // still sits below the grid within the same scroll
                // container and needs accounting for the same way.
                var mainEl = document.getElementById('app-main');
                var mainPaddingBottom = mainEl ? parseFloat(window.getComputedStyle(mainEl).paddingBottom) || 0 : 0;
                var cardPaddingBottom = parseFloat(window.getComputedStyle(grid.closest('.rounded-2xl')).paddingBottom) || 0;
                // Only actually occupies space when NOT hidden (an
                // empty month shows it right below the grid) -
                // offsetHeight alone excludes its own mt-4 margin, so
                // that has to be added back separately.
                var emptyNoteHeight = emptyNote.classList.contains('hidden')
                    ? 0
                    : emptyNote.offsetHeight + (parseFloat(window.getComputedStyle(emptyNote).marginTop) || 0);

                var available = window.innerHeight - top - mainPaddingBottom - cardPaddingBottom - emptyNoteHeight;

                // A floor only guards against a genuinely degenerate
                // (near-zero/negative) computation - it must never be
                // large enough to override a real, honestly-cramped
                // fit, or it silently reintroduces the same overflow
                // this function exists to prevent.
                grid.style.height = Math.max(available, 100) + 'px';
            }

            var monthFitResizeTimer = null;
            window.addEventListener('resize', function () {
                clearTimeout(monthFitResizeTimer);
                monthFitResizeTimer = setTimeout(fitMonthGridToViewport, 150);
            });

            // ---- Week/Day shared hour-grid rendering ----
            function buildHourBody(container, days) {
                container.innerHTML = '';

                var labelCol = document.createElement('div');
                labelCol.className = 'relative border-r border-gray-200';
                labelCol.style.gridRow = '1 / -1';
                labelCol.style.gridColumn = '1';
                for (var h = 0; h < 24; h++) {
                    var label = document.createElement('div');
                    label.className = 'absolute w-full -translate-y-1/2 pr-1.5 text-right text-[10px] text-gray-400';
                    label.style.top = (h * PX_PER_HOUR) + 'px';
                    label.textContent = (h === 0 ? '12 AM' : h < 12 ? h + ' AM' : h === 12 ? '12 PM' : (h - 12) + ' PM');
                    labelCol.appendChild(label);
                }
                container.appendChild(labelCol);

                days.forEach(function (day, dayIndex) {
                    var col = document.createElement('div');
                    col.className = 'relative border-r border-gray-100 last:border-r-0';
                    col.style.gridRow = '1 / -1';
                    col.style.gridColumn = String(dayIndex + 2);
                    col.style.height = DAY_HEIGHT + 'px';

                    for (var hh = 0; hh < 24; hh++) {
                        var line = document.createElement('div');
                        line.className = 'absolute inset-x-0 border-t border-gray-100';
                        line.style.top = (hh * PX_PER_HOUR) + 'px';
                        col.appendChild(line);
                    }

                    if (day.getTime() === today.getTime()) {
                        col.classList.add('bg-brand-50/30');
                    }

                    col.addEventListener('dragover', function (e) { e.preventDefault(); });
                    col.addEventListener('drop', (function (day) {
                        return function (e) {
                            e.preventDefault();
                            var id = e.dataTransfer.getData('text/plain');
                            var event = eventsById[id];
                            if (!event) return;
                            var rect = e.currentTarget.getBoundingClientRect();
                            var offsetY = e.clientY - rect.top;
                            var totalMinutes = Math.max(0, Math.round((offsetY / PX_PER_HOUR) * 60 / 15) * 15);
                            var newStart = new Date(day.getFullYear(), day.getMonth(), day.getDate(), 0, totalMinutes);
                            reschedule(id, newStart);
                        };
                    })(day));

                    day._col = col;
                    container.appendChild(col);
                });
            }

            function placeEventsInColumns(days, eventsByDate) {
                days.forEach(function (day) {
                    var key = dateKey(day);
                    var dayEvents = eventsByDate[key] || [];

                    dayEvents.forEach(function (event) {
                        var start = new Date(event.start_at);
                        var end = new Date(event.end_at);
                        var startMinutes = start.getHours() * 60 + start.getMinutes();
                        var durationMinutes = Math.max(20, (end.getTime() - start.getTime()) / 60000);

                        var block = document.createElement('button');
                        block.type = 'button';
                        block.draggable = true;
                        block.className = 'absolute inset-x-0.5 overflow-hidden rounded px-1.5 py-0.5 text-left text-[11px] font-medium ' + colorsFor(event).block;
                        block.style.top = (startMinutes / 60 * PX_PER_HOUR) + 'px';
                        block.style.height = Math.max(16, durationMinutes / 60 * PX_PER_HOUR) + 'px';
                        block.innerHTML = '<span class="font-semibold">' + formatTime(event.start_at) + '</span> ' + escapeHtml(event.title);
                        block.addEventListener('click', function () {
                            if (block.dataset.justResized) return;
                            openEventDetail(event);
                        });
                        block.addEventListener('dragstart', function (e) {
                            draggingEventId = event.id;
                            e.dataTransfer.setData('text/plain', String(event.id));
                            e.dataTransfer.effectAllowed = 'move';
                        });

                        var handle = document.createElement('div');
                        handle.className = 'absolute inset-x-0 bottom-0 h-1.5 cursor-ns-resize';
                        handle.addEventListener('mousedown', function (e) {
                            e.preventDefault();
                            e.stopPropagation();

                            // HTML5 drag (whole-block move) and this
                            // mouse-based resize both react to mousedown
                            // on the same element tree - disabling
                            // draggable for the interaction's duration
                            // stops the browser's native drag from also
                            // kicking in and fighting the resize.
                            block.draggable = false;
                            var startY = e.clientY;
                            var startHeightPx = parseFloat(block.style.height);
                            var minHeightPx = MIN_DURATION_MINUTES / 60 * PX_PER_HOUR;

                            function onMove(moveEvent) {
                                var deltaY = moveEvent.clientY - startY;
                                var rawHeight = Math.max(minHeightPx, startHeightPx + deltaY);
                                var snappedMinutes = Math.round((rawHeight / PX_PER_HOUR * 60) / 15) * 15;
                                var snappedHeight = Math.max(minHeightPx, snappedMinutes / 60 * PX_PER_HOUR);
                                block.style.height = snappedHeight + 'px';
                            }

                            function onUp() {
                                document.removeEventListener('mousemove', onMove);
                                document.removeEventListener('mouseup', onUp);
                                block.draggable = true;

                                var finalMinutes = Math.max(MIN_DURATION_MINUTES, Math.round((parseFloat(block.style.height) / PX_PER_HOUR * 60) / 15) * 15);
                                var newEnd = new Date(start.getTime() + finalMinutes * 60000);
                                resizeEnd(event.id, newEnd);

                                // The mouseup after a resize drag still
                                // fires a click on `block` right after -
                                // suppress just that one so it doesn't
                                // also pop open the detail modal.
                                block.dataset.justResized = 'true';
                                setTimeout(function () { delete block.dataset.justResized; }, 0);
                            }

                            document.addEventListener('mousemove', onMove);
                            document.addEventListener('mouseup', onUp);
                        });
                        block.appendChild(handle);

                        day._col.appendChild(block);
                    });
                });
            }

            function escapeHtml(text) {
                var div = document.createElement('div');
                div.textContent = text || '';
                return div.innerHTML;
            }

            function renderWeek(eventsByDate) {
                var start = startOfWeek(viewDate);
                var days = [];
                for (var i = 0; i < 7; i++) {
                    var d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
                    days.push(d);
                }
                var end = days[6];
                rangeLabel.textContent = start.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) + ' - ' +
                    end.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });

                weekHeader.innerHTML = '<div></div>';
                days.forEach(function (d) {
                    var cell = document.createElement('div');
                    cell.className = 'py-2 ' + (d.getTime() === today.getTime() ? 'font-semibold text-brand-700' : '');
                    cell.textContent = WEEKDAY_SHORT[d.getDay()] + ' ' + d.getDate();
                    weekHeader.appendChild(cell);
                });

                buildHourBody(weekBody, days);
                placeEventsInColumns(days, eventsByDate);

                var anyEvent = Object.keys(eventsByDate).length > 0;
                emptyNote.classList.toggle('hidden', anyEvent);
            }

            function renderDay(eventsByDate) {
                rangeLabel.textContent = viewDate.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
                dayHeader.textContent = viewDate.getTime() === today.getTime() ? 'Today' : '';

                var days = [viewDate];
                buildHourBody(dayBody, days);
                placeEventsInColumns(days, eventsByDate);

                var anyEvent = Object.keys(eventsByDate).length > 0;
                emptyNote.classList.toggle('hidden', anyEvent);
            }

            // ---- Fetch + dispatch ----
            function rangeForView() {
                if (currentView === 'week') {
                    var start = startOfWeek(viewDate);
                    var end = new Date(start.getFullYear(), start.getMonth(), start.getDate() + 7);
                    return { start: start, end: end };
                }

                if (currentView === 'day') {
                    var dayStart = new Date(viewDate.getFullYear(), viewDate.getMonth(), viewDate.getDate());
                    var dayEnd = new Date(viewDate.getFullYear(), viewDate.getMonth(), viewDate.getDate() + 1);
                    return { start: dayStart, end: dayEnd };
                }

                var year = viewDate.getFullYear();
                var month = viewDate.getMonth();
                var firstOfMonth = new Date(year, month, 1);
                var startOffset = firstOfMonth.getDay();
                var daysInMonth = new Date(year, month + 1, 0).getDate();
                var totalCells = Math.ceil((startOffset + daysInMonth) / 7) * 7;
                var gridStart = new Date(year, month, 1 - startOffset);
                var gridEnd = new Date(year, month, 1 - startOffset + totalCells);
                return { start: gridStart, end: gridEnd };
            }

            function loadAndRender() {
                var range = rangeForView();
                var requestedView = currentView;
                var requestToken = ++loadRequestToken;

                axios.get('/api/v1/calendar/events', { params: { start: range.start.toISOString(), end: range.end.toISOString() } }).then(function (response) {
                    // A slower-to-resolve request from a since-abandoned
                    // view/navigation must not clobber whatever the user
                    // has already switched to by the time it arrives.
                    if (requestToken !== loadRequestToken) {
                        return;
                    }

                    var eventsByDate = {};
                    eventsById = {};

                    response.data.data.forEach(function (event) {
                        eventsById[event.id] = event;
                        var start = new Date(event.start_at);
                        var key = dateKey(start);
                        (eventsByDate[key] = eventsByDate[key] || []).push(event);
                    });

                    if (requestedView === 'month') renderMonth(eventsByDate);
                    else if (requestedView === 'week') renderWeek(eventsByDate);
                    else renderDay(eventsByDate);
                });
            }

            function switchView(view) {
                currentView = view;
                monthView.classList.toggle('hidden', view !== 'month');
                weekView.classList.toggle('hidden', view !== 'week');
                dayView.classList.toggle('hidden', view !== 'day');

                document.querySelectorAll('.cal-view-tab').forEach(function (tab) {
                    var active = tab.dataset.calView === view;
                    tab.classList.toggle('bg-brand-50', active);
                    tab.classList.toggle('text-brand-700', active);
                    tab.classList.toggle('text-gray-500', !active);
                });

                loadAndRender();
            }

            document.querySelectorAll('.cal-view-tab').forEach(function (tab) {
                tab.addEventListener('click', function () { switchView(tab.dataset.calView); });
            });

            document.getElementById('cal-prev').addEventListener('click', function () {
                if (currentView === 'month') viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth() - 1, 1);
                else if (currentView === 'week') viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth(), viewDate.getDate() - 7);
                else viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth(), viewDate.getDate() - 1);
                loadAndRender();
            });
            document.getElementById('cal-next').addEventListener('click', function () {
                if (currentView === 'month') viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth() + 1, 1);
                else if (currentView === 'week') viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth(), viewDate.getDate() + 7);
                else viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth(), viewDate.getDate() + 1);
                loadAndRender();
            });
            document.getElementById('cal-today').addEventListener('click', function () {
                viewDate = new Date(today.getTime());
                loadAndRender();
            });

            // ---- Add a standalone (non-meeting) calendar entry ----
            var addEventModal = document.getElementById('cal-add-event-modal');
            var addTitleInput = document.getElementById('cal-add-title');
            var addStartInput = document.getElementById('cal-add-start');
            var addEndInput = document.getElementById('cal-add-end');
            var addDescriptionInput = document.getElementById('cal-add-description');
            var addErrorsBox = document.getElementById('cal-add-errors');
            var addErrorsList = document.getElementById('cal-add-errors-list');

            function selectedEventType() {
                var checked = document.querySelector('input[name="cal-add-type"]:checked');
                return checked ? checked.value : 'personal';
            }

            function toDatetimeLocalValue(date) {
                var pad = function (n) { return String(n).padStart(2, '0'); };
                return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) +
                    'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
            }

            function showAddEventErrors(messages) {
                addErrorsList.innerHTML = '';
                messages.forEach(function (message) {
                    var li = document.createElement('li');
                    li.textContent = message;
                    addErrorsList.appendChild(li);
                });
                addErrorsBox.classList.remove('hidden');
            }

            function openAddEventModal() {
                var defaultStart = new Date();
                defaultStart.setMinutes(defaultStart.getMinutes() - (defaultStart.getMinutes() % 30) + 30, 0, 0);
                var defaultEnd = new Date(defaultStart.getTime() + 30 * 60000);

                addTitleInput.value = '';
                document.querySelector('input[name="cal-add-type"][value="personal"]').checked = true;
                addStartInput.value = toDatetimeLocalValue(defaultStart);
                addEndInput.value = toDatetimeLocalValue(defaultEnd);
                addDescriptionInput.value = '';
                addErrorsBox.classList.add('hidden');

                addEventModal.classList.remove('hidden');
                addEventModal.classList.add('flex');
            }

            function closeAddEventModal() {
                addEventModal.classList.add('hidden');
                addEventModal.classList.remove('flex');
            }

            document.getElementById('cal-add-event-btn').addEventListener('click', openAddEventModal);
            document.getElementById('cal-add-event-close').addEventListener('click', closeAddEventModal);
            addEventModal.addEventListener('click', function (event) {
                if (event.target === addEventModal) closeAddEventModal();
            });

            document.getElementById('cal-add-save').addEventListener('click', function () {
                if (!addTitleInput.value.trim() || !addStartInput.value || !addEndInput.value) {
                    showAddEventErrors(['Title, start, and end are required.']);
                    return;
                }

                axios.post('/api/v1/calendar/events', {
                    title: addTitleInput.value.trim(),
                    event_type: selectedEventType(),
                    description: addDescriptionInput.value.trim() || null,
                    start_at: new Date(addStartInput.value).toISOString(),
                    end_at: new Date(addEndInput.value).toISOString(),
                    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                }).then(function () {
                    closeAddEventModal();
                    loadAndRender();
                }).catch(function (error) {
                    var errors = error.response && error.response.data && error.response.data.errors;
                    showAddEventErrors(errors ? Object.values(errors).flat() : ['Could not save this entry.']);
                });
            });

            switchView('month');
        });
    </script>
</x-layouts.app>
