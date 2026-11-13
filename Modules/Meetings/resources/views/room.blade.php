<x-layouts.app :title="$meeting->title . ' - Meet'">
    <div id="meeting-app" data-meeting-id="{{ $meeting->id }}" data-already-joined="{{ $alreadyJoined ? '1' : '0' }}" class="flex h-full flex-col overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">

        {{-- Lobby --}}
        <div id="lobby-panel" class="flex flex-1 flex-col items-center justify-center gap-4 p-8 text-center">
            <h1 class="text-xl font-semibold text-gray-900">{{ $meeting->title }}</h1>
            <p class="text-sm text-gray-500">Hosted by {{ $meeting->organizer->full_name }} &middot; Code {{ $meeting->meeting_code }}</p>
            {{-- A code alone meant anyone invited had to be told to
                 manually go find the join page and type it in - a real
                 tappable/copyable link (same /j/{code} URL guests
                 already see once they're in) is what people actually
                 forward in a chat message. --}}
            <div class="flex flex-wrap items-center justify-center gap-2 rounded-lg bg-brand-50 px-3 py-2 text-xs text-brand-700">
                <span>Invite link:</span>
                <code id="lobby-share-link-value" class="rounded bg-white px-2 py-0.5 text-gray-700">{{ url('/j/'.$meeting->meeting_code) }}</code>
                <button type="button" id="lobby-share-link-copy" class="rounded-lg border border-brand-200 px-2 py-0.5 font-medium text-brand-700 hover:bg-brand-100">Copy</button>
            </div>
            <video id="lobby-preview" autoplay playsinline muted class="h-48 w-64 rounded-xl bg-gray-900 object-cover"></video>
            @if ($meeting->hasPassword())
                <input type="password" id="lobby-password" placeholder="Meeting password" class="w-64 rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
            @endif
            <button type="button" id="lobby-join-btn" class="rounded-full bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">Join meeting</button>
            <p id="lobby-error" class="hidden text-xs text-red-600"></p>
        </div>

        {{-- Waiting for host --}}
        <div id="waiting-panel" class="hidden flex-1 flex-col items-center justify-center gap-3 p-8 text-center">
            <p class="text-base font-medium text-gray-900">Waiting for the host to let you in&hellip;</p>
            <p class="text-sm text-gray-500">You'll join automatically once admitted.</p>
        </div>

        {{-- Ended --}}
        <div id="ended-panel" class="hidden flex-1 flex-col items-center justify-center gap-4 p-8 text-center">
            <span class="flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 text-gray-500">
                <x-icons.phone-end class="h-7 w-7" />
            </span>
            <p class="text-lg font-semibold text-gray-900" id="ended-message">This meeting has ended.</p>
            <a href="{{ route('meetings.index') }}" class="rounded-full bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-brand-700">
                Back to meetings
            </a>
        </div>

        {{-- Active room. Below `lg` this becomes the Google-Meet-style
             mobile layout: a minimal title bar, a full-bleed video grid,
             and a floating semi-transparent bottom control bar (icon-only)
             instead of the desktop's top toolbar with icon+label buttons.
             Every control keeps the exact same id either way - only its
             container's responsive classes change - so room-script.blade.php
             needs no changes at all. --}}
        <div id="room-panel" class="hidden flex-1 flex-col overflow-hidden">
            <div class="border-b border-gray-100 px-4 py-2 lg:flex lg:flex-wrap lg:items-center lg:justify-between lg:gap-y-2 lg:gap-x-3">
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-gray-900">{{ $meeting->title }}</p>
                    <p class="text-xs text-gray-400">Code {{ $meeting->meeting_code }}</p>
                </div>
                <div id="room-controls" class="fixed inset-x-0 bottom-0 z-20 flex flex-wrap items-center justify-center gap-1 bg-black/70 px-3 py-3 backdrop-blur-sm lg:static lg:justify-end lg:gap-1 lg:bg-transparent lg:px-0 lg:py-0 lg:backdrop-blur-none">
                    <button type="button" id="mobile-panel-toggle-btn" title="People &amp; chat" class="flex items-center gap-1.5 rounded-full p-3 text-white hover:bg-white/10 lg:hidden">
                        <x-icons.contacts class="h-5 w-5" />
                    </button>
                    <button type="button" id="raise-hand-btn" title="Raise hand" class="flex items-center gap-1.5 rounded-full p-3 text-white hover:bg-white/10 lg:rounded-lg lg:p-1.5 lg:px-2.5 lg:text-gray-500 lg:hover:bg-gray-100">
                        <x-icons.hand-raised class="h-5 w-5 lg:h-4 lg:w-4" /><span class="hidden lg:inline">Raise hand</span>
                    </button>
                    <button type="button" id="screen-share-btn" title="Share screen" class="flex items-center gap-1.5 rounded-full p-3 text-white hover:bg-white/10 lg:rounded-lg lg:p-1.5 lg:px-2.5 lg:text-gray-500 lg:hover:bg-gray-100">
                        <x-icons.screen-share class="h-5 w-5 lg:h-4 lg:w-4" /><span class="hidden lg:inline">Share screen</span>
                    </button>
                    <button type="button" id="whiteboard-btn" title="Whiteboard" class="flex items-center gap-1.5 rounded-full p-3 text-white hover:bg-white/10 lg:rounded-lg lg:p-1.5 lg:px-2.5 lg:text-gray-500 lg:hover:bg-gray-100">
                        <x-icons.pencil class="h-5 w-5 lg:h-4 lg:w-4" /><span class="hidden lg:inline">Whiteboard</span>
                    </button>
                    <button type="button" id="mx-cc-btn" title="Captions" aria-pressed="false" class="flex items-center gap-1.5 rounded-full p-3 text-white hover:bg-white/10 lg:rounded-lg lg:p-1.5 lg:px-2.5 lg:text-gray-500 lg:hover:bg-gray-100">
                        <svg class="h-5 w-5 lg:h-4 lg:w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M10.5 10a2 2 0 1 0 0 4M16.5 10a2 2 0 1 0 0 4"/></svg><span class="hidden lg:inline">Captions</span>
                    </button>
                    <button type="button" id="mx-more-btn" title="More: recording, breakout rooms, notes" aria-haspopup="menu" class="flex items-center gap-1.5 rounded-full p-3 text-white hover:bg-white/10 lg:rounded-lg lg:p-1.5 lg:px-2.5 lg:text-gray-500 lg:hover:bg-gray-100">
                        <svg class="h-5 w-5 lg:h-4 lg:w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg><span class="hidden lg:inline">More</span>
                    </button>
                    <button type="button" id="lock-btn" title="Lock" class="hidden items-center gap-1.5 rounded-full p-3 text-white hover:bg-white/10 lg:rounded-lg lg:p-1.5 lg:px-2.5 lg:text-gray-500 lg:hover:bg-gray-100">
                        <x-icons.lock class="h-5 w-5 lg:h-4 lg:w-4" /><span class="hidden lg:inline">Lock</span>
                    </button>
                    <button type="button" id="mute-self-btn" title="Mute" class="flex items-center gap-1.5 rounded-full p-3 text-white hover:bg-white/10 lg:rounded-lg lg:p-1.5 lg:px-2.5 lg:text-gray-500 lg:hover:bg-gray-100">
                        <x-icons.mic class="h-5 w-5 lg:h-4 lg:w-4" /><span class="hidden lg:inline">Mute</span>
                    </button>
                    {{-- Hidden by default - only shown once setSinkId()
                         support is confirmed (see updateAudioOutputAvailability()
                         in room-script), same "don't show a control that
                         can't do anything" rule as flip-camera. --}}
                    <button type="button" id="audio-output-btn" title="Speaker" class="hidden items-center gap-1.5 rounded-full p-3 text-white hover:bg-white/10 lg:rounded-lg lg:p-1.5 lg:px-2.5 lg:text-gray-500 lg:hover:bg-gray-100">
                        <x-icons.volume class="h-5 w-5 lg:h-4 lg:w-4" /><span class="hidden lg:inline">Speaker</span>
                    </button>
                    {{-- Fixed (not absolute) rather than anchored to
                         #room-controls itself - that container is only
                         `position: fixed` below the lg breakpoint
                         (`lg:static` at desktop), so an absolute child
                         would resolve against a different ancestor at
                         each width. Fixed positioning avoids that
                         entirely with one consistent placement. --}}
                    <div id="audio-output-menu" class="fixed bottom-20 right-4 z-30 hidden w-52 flex-col rounded-xl bg-white py-1 shadow-2xl"></div>
                    <button type="button" id="camera-self-btn" title="Camera off" class="flex items-center gap-1.5 rounded-full p-3 text-white hover:bg-white/10 lg:rounded-lg lg:p-1.5 lg:px-2.5 lg:text-gray-500 lg:hover:bg-gray-100">
                        <x-icons.video class="h-5 w-5 lg:h-4 lg:w-4" /><span class="hidden lg:inline">Camera off</span>
                    </button>
                    {{-- Hidden by default - only shown once a genuine
                         second camera is confirmed present (see
                         updateFlipCameraAvailability() in room-script),
                         same "don't show a control that can't do
                         anything" rule as everywhere else. --}}
                    <button type="button" id="flip-camera-btn" title="Flip camera" class="hidden items-center gap-1.5 rounded-full p-3 text-white hover:bg-white/10 lg:rounded-lg lg:p-1.5 lg:px-2.5 lg:text-gray-500 lg:hover:bg-gray-100">
                        <x-icons.flip-camera class="h-5 w-5 lg:h-4 lg:w-4" /><span class="hidden lg:inline">Flip camera</span>
                    </button>
                    <button type="button" id="end-btn" title="End for everyone" class="hidden items-center gap-1.5 rounded-full bg-red-600 p-3 text-white hover:bg-red-700 lg:rounded-lg lg:p-1.5 lg:px-2.5">
                        <x-icons.phone-end class="h-5 w-5 lg:h-4 lg:w-4" /><span class="hidden lg:inline">End for everyone</span>
                    </button>
                    <button type="button" id="leave-btn" title="Leave" class="flex items-center gap-1.5 rounded-full bg-gray-700 p-3 text-white hover:bg-gray-600 lg:rounded-lg lg:bg-gray-800 lg:p-1.5 lg:px-2.5 lg:hover:bg-gray-900">
                        <x-icons.phone-end class="h-5 w-5 lg:h-4 lg:w-4" /><span class="hidden lg:inline">Leave</span>
                    </button>
                </div>
            </div>

            <div class="flex flex-1 overflow-hidden">
                {{-- Starts as a single-tile layout (tiles-1 below) - real
                     layout is driven by updateVideoGridLayout() in
                     room-script.blade.php as tiles are added/removed,
                     not this fixed initial class. --}}
                <div id="video-grid" class="tiles-1 grid flex-1 gap-2 overflow-y-auto bg-gray-900 p-2 pb-20 lg:pb-2"></div>

                {{-- pb-20 matches #video-grid's own mobile bottom padding -
                     without it the canvas's bottom ~80px sits under the
                     floating control bar on mobile. --}}
                <div id="whiteboard-overlay" class="hidden flex-1 flex-col bg-white pb-20 lg:pb-0">
                    <div class="flex items-center gap-2 border-b border-gray-100 px-3 py-2">
                        <button type="button" id="wb-tool-pen" class="rounded-lg bg-brand-50 px-2 py-1 text-xs font-medium text-brand-700">Pen</button>
                        <button type="button" id="wb-tool-rectangle" class="rounded-lg px-2 py-1 text-xs font-medium text-gray-500 hover:bg-gray-100">Rectangle</button>
                        <button type="button" id="wb-clear-btn" class="rounded-lg px-2 py-1 text-xs font-medium text-gray-500 hover:bg-gray-100">Clear</button>
                        <button type="button" id="wb-undo-btn" class="rounded-lg px-2 py-1 text-xs font-medium text-gray-500 hover:bg-gray-100">Undo</button>
                        <button type="button" id="wb-redo-btn" class="rounded-lg px-2 py-1 text-xs font-medium text-gray-500 hover:bg-gray-100">Redo</button>
                        <input type="color" id="wb-color" value="#111827" class="h-6 w-8">
                        <button type="button" id="wb-close-btn" class="ml-auto rounded-lg px-2 py-1 text-xs font-medium text-gray-500 hover:bg-gray-100">Close</button>
                    </div>
                    <canvas id="wb-canvas" width="900" height="600" class="flex-1 cursor-crosshair touch-none"></canvas>
                </div>

                {{-- Static sidebar at lg+; a full-screen slide-up overlay
                     below it, opened via #mobile-panel-toggle-btn in the
                     floating control bar and closed via its own close button. --}}
                <div id="room-side-panel" class="fixed inset-0 z-30 hidden w-full flex-col bg-white lg:static lg:inset-auto lg:z-auto lg:flex lg:w-72 lg:shrink-0 lg:border-l lg:border-gray-100">
                    <div class="flex items-center border-b border-gray-100 text-sm">
                        <button type="button" id="tab-participants" class="flex-1 border-b-2 border-brand-600 px-3 py-2 font-medium text-brand-700">People</button>
                        <button type="button" id="tab-chat" class="flex-1 border-b-2 border-transparent px-3 py-2 font-medium text-gray-500 hover:text-gray-700">Chat</button>
                        <button type="button" id="room-side-panel-close-btn" class="shrink-0 px-3 py-2 text-gray-400 hover:text-gray-600 lg:hidden">&times;</button>
                    </div>

                    <div id="participants-panel" class="flex-1 overflow-y-auto">
                        <div id="waiting-room-list" class="hidden border-b border-gray-100 bg-amber-50 px-3 py-2"></div>
                        <ul id="participant-list" class="divide-y divide-gray-100 text-sm"></ul>
                    </div>

                    <div id="chat-panel" class="hidden flex-1 flex-col overflow-hidden">
                        <div id="meeting-messages" class="flex-1 space-y-2 overflow-y-auto px-3 py-2"></div>
                        <form id="meeting-chat-form" class="flex items-center gap-2 border-t border-gray-100 px-3 py-2">
                            <input id="meeting-chat-input" type="text" autocomplete="off" placeholder="Message&hellip;" class="flex-1 rounded-full border-gray-300 text-base shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                            <button type="submit" class="rounded-full bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700">Send</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include('meetings::partials.room-script')
    @include('meetings::partials.room-extras')
</x-layouts.app>
