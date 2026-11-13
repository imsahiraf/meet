<x-layouts.app title="Call history - Meet">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold text-gray-900">Call history</h1>
        <form method="GET" action="{{ route('calls.history') }}" class="flex gap-2">
            <select name="status" onchange="this.form.submit()" class="!w-auto text-sm">
                <option value="">All statuses</option>
                @foreach (['ended' => 'Completed', 'missed' => 'Missed', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'] as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
        <ul class="divide-y divide-gray-100">
            @forelse ($calls as $call)
                @php
                    // caller_id/receiver_id are the ORIGINATING pair
                    // (see the Call model) - still exactly what a
                    // history row wants for its headline "call with X".
                    // A call that grew past two people via "Add member"
                    // says so via the extra participants below instead
                    // of pretending it was 1:1.
                    $isOutgoing = $call->caller_id === auth()->id();
                    $other = $isOutgoing ? $call->receiver : $call->caller;
                    $participantCount = max($call->history?->participant_count ?? 0, $call->participants->whereNotNull('joined_at')->count(), 2);
                    $extraParticipants = $participantCount - 2;
                    $directionLabel = match (true) {
                        $call->call_status === 'missed' && ! $isOutgoing => 'Missed',
                        $isOutgoing => 'Outgoing',
                        default => 'Incoming',
                    };
                    $statusColor = match ($call->call_status) {
                        'missed', 'rejected' => 'text-red-600',
                        'cancelled' => 'text-gray-500',
                        default => 'text-emerald-600',
                    };
                @endphp
                {{-- A row used to be a plain <li> with no way to see
                     more or call back - now a button opening the
                     detail popup below, same data every push
                     notification/call-back flow needs anyway. --}}
                <li>
                    <button type="button"
                        class="call-history-row flex w-full items-center justify-between gap-4 px-5 py-4 text-left hover:bg-gray-50"
                        data-other-id="{{ $other?->id }}"
                        data-other-name="{{ $other?->full_name ?? 'Unknown' }}"
                        data-other-photo="{{ $other?->photoFor(auth()->user()) }}"
                        data-call-type="{{ $call->call_type }}"
                        data-call-status="{{ $call->call_status }}"
                        data-direction="{{ $directionLabel }}"
                        data-duration="{{ $call->duration_seconds }}"
                        data-started-at="{{ $call->started_at->toISOString() }}"
                        data-conversation-id="{{ $call->conversation_id }}"
                        data-participant-count="{{ $participantCount }}"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            @if ($photo = $other?->photoFor(auth()->user()))
                            <img src="{{ $photo }}" alt="" class="h-10 w-10 shrink-0 rounded-full" style="object-fit:cover">
                            @else
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700">
                                {{ strtoupper(substr($other?->full_name ?? '?', 0, 1)) }}
                            </span>
                            @endif
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-900">
                                    {{ $other?->full_name ?? 'Unknown' }}@if ($extraParticipants > 0)<span class="font-normal text-gray-500"> &amp; {{ $extraParticipants }} {{ Str::plural('other', $extraParticipants) }}</span>@endif
                                </p>
                                <p class="text-xs {{ $statusColor }}">
                                    {{ $call->call_type === 'video' ? '🎥' : '📞' }} {{ $extraParticipants > 0 ? 'Group · ' : '' }}{{ $directionLabel }}
                                    @if ($call->call_status === 'ended')
                                        &middot; {{ gmdate('i:s', $call->duration_seconds ?? 0) }}
                                    @endif
                                </p>
                            </div>
                        </div>
                        <span class="shrink-0 text-xs text-gray-400">{{ $call->started_at->format('M j, g:i A') }}</span>
                    </button>
                </li>
            @empty
                <li class="px-5 py-14 text-center text-sm text-gray-400">No calls yet.</li>
            @endforelse
        </ul>
    </div>

    {{-- Call detail popup - tapping a history row shows this instead
         of nothing happening; "Call back" reuses the exact same
         window.MeetCalls.start() the voice/video call buttons
         elsewhere in the app already call. --}}
    <div id="call-history-detail-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">
        <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-xl">
            <div class="flex items-center gap-3">
                <span id="call-history-detail-avatar" class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-brand-100 text-lg font-semibold text-brand-700"></span>
                <div class="min-w-0">
                    <p id="call-history-detail-name" class="truncate text-base font-semibold text-gray-900"></p>
                    <p id="call-history-detail-meta" class="text-sm text-gray-500"></p>
                </div>
            </div>
            <p id="call-history-detail-time" class="mt-3 text-xs text-gray-400"></p>
            <div class="mt-5 flex items-center justify-end gap-2">
                <button type="button" id="call-history-detail-close" class="rounded-lg px-3 py-2 text-sm font-medium text-gray-500 hover:bg-gray-100">Close</button>
                <button type="button" id="call-history-detail-video" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Video call</button>
                <button type="button" id="call-history-detail-voice" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Call back</button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var modal = document.getElementById('call-history-detail-modal');
            var avatar = document.getElementById('call-history-detail-avatar');
            var nameEl = document.getElementById('call-history-detail-name');
            var metaEl = document.getElementById('call-history-detail-meta');
            var timeEl = document.getElementById('call-history-detail-time');
            var voiceBtn = document.getElementById('call-history-detail-voice');
            var videoBtn = document.getElementById('call-history-detail-video');
            var current = null;

            function closeModal() {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                current = null;
            }

            document.querySelectorAll('.call-history-row').forEach(function (row) {
                row.addEventListener('click', function () {
                    current = {
                        otherId: row.dataset.otherId ? parseInt(row.dataset.otherId, 10) : null,
                        otherName: row.dataset.otherName,
                        conversationId: row.dataset.conversationId ? parseInt(row.dataset.conversationId, 10) : null,
                    };

                    avatar.textContent = '';
                    if (row.dataset.otherPhoto) {
                        var img = document.createElement('img');
                        img.src = row.dataset.otherPhoto; img.alt = ''; img.style.cssText = 'width:100%;height:100%;border-radius:50%;object-fit:cover';
                        avatar.appendChild(img);
                    } else {
                        avatar.textContent = (row.dataset.otherName || '?').trim().charAt(0).toUpperCase();
                    }
                    nameEl.textContent = row.dataset.otherName;

                    var participantCount = parseInt(row.dataset.participantCount || '2', 10);
                    var meta = (participantCount > 2 ? 'Group ' : '') + (row.dataset.callType === 'video' ? 'Video call' : 'Voice call') + ' · ' + row.dataset.direction;
                    if (participantCount > 2) {
                        meta += ' · ' + participantCount + ' people';
                    }
                    if (row.dataset.callStatus === 'ended' && row.dataset.duration) {
                        var seconds = parseInt(row.dataset.duration, 10);
                        var mins = String(Math.floor(seconds / 60)).padStart(2, '0');
                        var secs = String(seconds % 60).padStart(2, '0');
                        meta += ' · ' + mins + ':' + secs;
                    }
                    metaEl.textContent = meta;
                    timeEl.textContent = new Date(row.dataset.startedAt).toLocaleString(undefined, {
                        weekday: 'long', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit',
                    });

                    var canCallBack = !!current.otherId;
                    voiceBtn.classList.toggle('hidden', !canCallBack);
                    videoBtn.classList.toggle('hidden', !canCallBack);

                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                });
            });

            document.getElementById('call-history-detail-close').addEventListener('click', closeModal);
            modal.addEventListener('click', function (event) {
                if (event.target === modal) closeModal();
            });

            function callBack(type) {
                if (!current || !current.otherId || !window.MeetCalls) {
                    return;
                }
                // closeModal() clears `current` - capture what's
                // needed before calling it, not after.
                var target = current;
                closeModal();
                window.MeetCalls.start(target.otherId, type, target.otherName, target.conversationId);
            }

            voiceBtn.addEventListener('click', function () { callBack('voice'); });
            videoBtn.addEventListener('click', function () { callBack('video'); });
        });
    </script>
</x-layouts.app>
