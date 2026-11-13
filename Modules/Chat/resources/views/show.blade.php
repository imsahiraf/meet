<x-layouts.app :title="($group?->name ?? $otherUser?->full_name ?? 'Chat') . ' - Meet'" :full-bleed-mobile="true">
    <div id="chat-app-container" class="flex h-full flex-col overflow-hidden rounded-none border-0 shadow-none bg-white transition-[height] duration-150 lg:rounded-2xl lg:border lg:border-gray-100 lg:shadow-sm">
        <div id="chat-thread-header" class="flex items-center gap-2 border-b border-gray-100 px-5 py-3">
            <a href="{{ route('chat.index') }}" class="shrink-0 text-gray-400 hover:text-gray-600" title="Back to conversations">&larr;</a>
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700">
                {{ strtoupper(substr($group?->name ?? $otherUser?->full_name ?? '?', 0, 1)) }}
            </span>
            <div class="min-w-0 flex-1">
                <p class="flex items-center gap-1.5 truncate text-sm font-semibold text-gray-900">
                    <span class="truncate">{{ $group?->name ?? $otherUser?->full_name ?? 'Unknown' }}</span>
                    {{-- Previously the only feedback after toggling this
                         was a one-frame highlight on the option button
                         itself, immediately followed by the picker
                         closing - nothing stayed visible to show the
                         chat's current state. This is the missing
                         persistent indicator, kept live for both
                         participants by updateDisappearingIndicator()
                         (called on load, right after picking an option,
                         and every pollConversationMeta() tick). --}}
                    <span id="disappearing-indicator" class="hidden shrink-0 items-center gap-0.5 whitespace-nowrap rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] font-normal text-gray-500" title="Disappearing messages are on">
                        <span aria-hidden="true">&#8987;</span><span id="disappearing-indicator-label"></span>
                    </span>
                </p>
                @if ($group)
                    <p class="text-xs text-gray-400">{{ $memberCount }} {{ $memberCount === 1 ? 'member' : 'members' }}</p>
                @else
                    {{-- Deliberately empty on first render, not a raw
                         presence_status read - that would bypass
                         last_seen_visibility/Super Access gating
                         entirely. pollConversationMeta() (called
                         immediately below, not just on its interval)
                         fills this in via the already-gated API. --}}
                    <p id="presence-status" class="truncate text-xs text-gray-400"></p>
                @endif
            </div>
            @if ($group)
                <a href="{{ route('groups.show', $group) }}" class="shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium text-brand-600 transition hover:bg-brand-50">
                    Group info
                </a>
            @else
                <button type="button" id="voice-call-button" class="shrink-0 rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600" title="Voice call">&#128222;</button>
                <button type="button" id="video-call-button" class="shrink-0 rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600" title="Video call">&#127909;</button>
            @endif
            <div class="relative shrink-0">
                <button type="button" id="chat-menu-btn" class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600" title="More options">
                    <x-icons.kebab class="h-5 w-5" />
                </button>
                <div id="chat-menu-dropdown" class="absolute right-0 top-full z-10 mt-1 hidden w-48 rounded-lg border border-gray-100 bg-white py-1 shadow-lg">
                    @unless ($group)
                        <form method="POST" action="{{ route('chat.mute', $conversation) }}">
                            @csrf
                            <input type="hidden" name="muted" value="{{ $isMuted ? '0' : '1' }}">
                            <button type="submit" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">
                                {{ $isMuted ? 'Unmute' : 'Mute' }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('chat.archive', $conversation) }}">
                            @csrf
                            <input type="hidden" name="unarchive" value="{{ $isArchived ? '1' : '0' }}">
                            <button type="submit" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">
                                {{ $isArchived ? 'Unarchive' : 'Archive' }}
                            </button>
                        </form>
                    @endunless
                    <button type="button" id="chat-wallpaper-btn" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">
                        Change wallpaper
                    </button>
                    <button type="button" id="chat-disappearing-btn" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">
                        Disappearing messages
                    </button>
                    <a href="/api/v1/conversations/{{ $conversation->id }}/export" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">
                        Export chat
                    </a>
                    <button type="button" id="message-select-btn" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">
                        Select messages
                    </button>
                </div>
            </div>
        </div>

        {{-- WhatsApp-style message multi-select bulk bar - swapped in for
             the normal thread header while select mode is active. --}}
        <div id="message-bulk-bar" class="hidden items-center justify-between gap-3 border-b border-gray-100 bg-brand-50/60 px-4 py-3">
            <div class="flex items-center gap-3">
                <button type="button" id="message-bulk-cancel-btn" class="rounded-lg p-1.5 text-gray-500 hover:bg-white/60 hover:text-gray-700" title="Cancel">&times;</button>
                <span class="text-sm font-semibold text-brand-800"><span id="message-bulk-count">0</span> selected</span>
            </div>
            <div class="flex items-center gap-1">
                <button type="button" id="message-bulk-star-btn" disabled data-action="star" class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-600 hover:bg-white/60 disabled:cursor-not-allowed disabled:opacity-40">
                    Star
                </button>
                <button type="button" id="message-bulk-forward-btn" disabled data-action="forward" class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-600 hover:bg-white/60 disabled:cursor-not-allowed disabled:opacity-40">
                    Forward
                </button>
                <button type="button" id="message-bulk-delete-btn" disabled data-action="delete-me" class="rounded-lg px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-white/60 disabled:cursor-not-allowed disabled:opacity-40">
                    Delete
                </button>
            </div>
        </div>

        <div id="wallpaper-picker" class="hidden border-b border-gray-100 px-5 py-3">
            <p class="mb-2 text-xs font-medium text-gray-500">Choose a wallpaper</p>
            <div id="wallpaper-swatches" class="flex flex-wrap gap-2"></div>
        </div>

        <div id="disappearing-picker" class="hidden border-b border-gray-100 px-5 py-3" data-disappearing-seconds="{{ $conversation->disappearing_messages_seconds }}">
            <p class="mb-2 text-xs font-medium text-gray-500">Disappearing messages - new messages in this {{ $group ? 'group' : 'chat' }} will auto-delete for everyone after:</p>
            <div id="disappearing-options" class="flex flex-wrap gap-2"></div>
        </div>

        <div id="chat-error" class="hidden border-b border-red-100 bg-red-50 px-5 py-2 text-xs text-red-700"></div>
        <p id="e2e-indicator" class="hidden border-b border-gray-100 px-5 py-1.5 text-center text-[11px] font-medium"></p>

        {{-- WhatsApp-style pinned-message banner. Only one message can
             ever be pinned per chat (see MessageService::togglePin()), so
             this is always exactly one row, not a list. Tapping it jumps
             to the message; the × unpins it directly from here. --}}
        <div id="pinned-banner" class="hidden cursor-pointer items-center gap-2 border-b border-gray-100 bg-brand-50/60 px-5 py-2 text-xs text-gray-700">
            <x-icons.pin class="h-3.5 w-3.5 shrink-0 text-brand-600" />
            <span id="pinned-banner-text" class="min-w-0 flex-1 truncate"></span>
            <button type="button" id="pinned-banner-unpin" class="shrink-0 rounded p-1 text-gray-400 hover:bg-white/60 hover:text-gray-600" title="Unpin">&times;</button>
        </div>

        {{-- min-h-0 is load-bearing: a flex child otherwise refuses to
             shrink below its own content's natural height (the message
             list can easily be tall), so whenever a sibling like the
             attachment preview or reply banner appeared, this wouldn't
             shrink to make room for it - it pushed the composer out of
             the fixed-height column instead ("footer moves up when
             attaching"), rather than the message list simply scrolling
             a bit shorter. --}}
        <div id="messages" class="min-h-0 flex-1 space-y-3 overflow-y-auto overscroll-y-contain px-5 py-4" data-conversation-id="{{ $conversation->id }}" data-current-user-id="{{ auth()->id() }}">
            <div class="mx-auto max-w-xs rounded-lg bg-gray-50 px-3 py-2 text-center text-[11px] text-gray-400">
                Messages in this {{ $group ? 'group' : 'chat' }} are only visible to {{ $group ? 'its members' : 'you and the other participant' }}.
            </div>
            {{-- Populated by JS on load and via polling. --}}
        </div>

        <div id="typing-indicator" class="hidden px-5 pb-1 text-xs italic text-gray-400">typing&hellip;</div>

        <div id="reply-banner" class="hidden items-center justify-between gap-2 border-t border-gray-100 bg-gray-50 px-4 py-2 text-xs text-gray-600">
            <span id="reply-banner-text" class="truncate"></span>
            <button type="button" id="reply-banner-cancel" class="shrink-0 text-gray-400 hover:text-gray-600">&times;</button>
        </div>

        <div id="forward-picker" class="hidden max-h-48 overflow-y-auto border-t border-gray-100 px-4 py-2"></div>

        <div id="attachment-preview" class="hidden flex-col gap-2 border-t border-gray-100 bg-gray-50 px-4 py-2 text-xs text-gray-600">
            <div id="attachment-preview-thumbs" class="flex flex-wrap gap-2"></div>
            <div class="flex items-center justify-between gap-2">
                <span id="attachment-preview-text" class="truncate"></span>
                {{-- Images to PDF: turns the selected images into one PDF document before sending --}}
                <button type="button" id="attachment-make-pdf" class="hidden shrink-0 rounded-full border border-gray-300 bg-white px-2.5 py-1 font-medium text-gray-700 hover:bg-gray-100" title="Send the selected images as one PDF">Make PDF</button>
                <label class="flex shrink-0 items-center gap-1">
                    <input type="checkbox" id="one-view-toggle" class="h-3.5 w-3.5 rounded border-gray-300 text-brand-600">
                    View once
                </label>
                <button type="button" id="attachment-cancel" class="shrink-0 text-gray-400 hover:text-gray-600">&times;</button>
            </div>
        </div>

        <script src="{{ asset('js/images-to-pdf.js') }}?v={{ config('app.version') }}"></script>
        <form id="composer" class="flex items-center gap-2 border-t border-gray-100 px-4 py-3">
            <input type="file" id="attachment-input-gallery" multiple class="hidden" accept="image/*,video/*">
            <input type="file" id="attachment-input-document" multiple class="hidden">

            <div id="composer-normal-row" class="flex flex-1 items-center gap-2">
                <div class="relative shrink-0">
                    <button type="button" id="attachment-button" class="rounded-full p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600" title="Attach">&#128206;</button>
                    {{-- WhatsApp-style attachment popup - Camera/Gallery/Document
                         only, per current scope; opens upward since the composer
                         sits at the bottom of the screen. --}}
                    <div id="attachment-menu" class="absolute bottom-full left-0 z-20 mb-2 hidden w-48 rounded-xl border border-gray-100 bg-white py-1.5 shadow-lg">
                        <button type="button" id="attachment-menu-camera" class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm text-gray-700 hover:bg-gray-50">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-rose-100 text-base">&#128247;</span> Camera
                        </button>
                        <button type="button" id="attachment-menu-gallery" class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm text-gray-700 hover:bg-gray-50">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-purple-100 text-base">&#128444;&#65039;</span> Gallery
                        </button>
                        <button type="button" id="attachment-menu-document" class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm text-gray-700 hover:bg-gray-50">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-100 text-base">&#128196;</span> Document
                        </button>
                    </div>
                </div>

                <button type="button" id="voice-note-button" class="shrink-0 rounded-full p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600" title="Record a voice note">&#127908;</button>
                {{-- A real multi-line textarea (not a single-line input)
                     that grows with the message up to a max height, then
                     scrolls internally beyond that - matching WhatsApp,
                     rather than a single line you can only scroll
                     sideways through. rows="1" + JS auto-grow (see
                     autoGrowComposer()) keeps it exactly one line tall
                     until there's actually a second line of content.
                     Enter sends; Shift+Enter inserts a newline. --}}
                <textarea id="composer-input" rows="1" autocomplete="off" placeholder="Type a message&hellip;" enterkeyhint="send"
                          class="composer-input-scroll max-h-32 flex-1 resize-none overflow-y-hidden rounded-2xl border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm"></textarea>
                <button type="submit" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-600 text-white hover:bg-brand-700 disabled:cursor-not-allowed disabled:bg-gray-300 disabled:hover:bg-gray-300" title="Send">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                </button>
            </div>

            {{-- WhatsApp-style recording bar - replaces the normal
                 composer row entirely while recording (real apps don't
                 show a text input alongside it). Trash discards outright;
                 Pause/Resume toggles mid-recording; the green arrow stops
                 and sends immediately - there's no separate preview step
                 for a voice note, matching real WhatsApp. --}}
            <div id="voice-recording-bar" class="hidden w-full items-center gap-3">
                <button type="button" id="voice-recording-cancel" class="shrink-0 rounded-full p-2 text-gray-400 hover:bg-gray-100 hover:text-red-500" title="Discard recording">&#128465;&#65039;</button>
                {{-- WhatsApp-style swipe-to-cancel, layered on top of the
                     explicit trash button above (kept for accessibility/
                     discoverability - a gesture-only cancel is easy to
                     miss). Dragging this pill left past a threshold
                     discards the recording the same way tapping trash
                     does; released before the threshold, it snaps back
                     and recording continues untouched. --}}
                <div id="voice-recording-pill" class="relative flex flex-1 touch-pan-y items-center gap-2 overflow-hidden rounded-full bg-gray-100 px-3 py-2 select-none">
                    <span id="voice-recording-dot" class="h-2 w-2 shrink-0 rounded-full bg-red-500"></span>
                    <div id="voice-waveform" class="flex h-6 flex-1 items-center gap-[2px] overflow-hidden"></div>
                    <span id="voice-recording-time" class="shrink-0 text-xs font-medium text-gray-500">0:00</span>
                    <span id="voice-slide-cancel-hint" class="pointer-events-none absolute inset-0 hidden items-center justify-center gap-1.5 bg-gray-100 text-xs font-medium text-gray-500">&larr; Slide to cancel</span>
                </div>
                <button type="button" id="voice-recording-pause" class="shrink-0 rounded-full border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-50">Pause</button>
                <button type="button" id="voice-recording-send" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white hover:bg-emerald-600" title="Send">&#10148;</button>
            </div>
        </form>
    </div>

    {{-- One View media always opens here full-screen (WhatsApp-style),
         never inline in the bubble - see viewOneViewMedia(). Images
         auto-close after a few seconds; video autoplays and closes
         itself once playback ends. --}}
    <div id="one-view-modal" class="one-view-protected fixed inset-0 z-50 hidden items-center justify-center bg-black/90 p-4" oncontextmenu="return false">
        <div id="one-view-modal-content" class="flex max-h-full max-w-full items-center justify-center"></div>
        {{-- Covers the media the instant the tab/window loses focus -
             a screenshot/screen-recording key combo (Print Screen,
             Win+Shift+S, Cmd+Shift+4, an OS-level recorder) never
             fires any JS event a web page can see, so this can't
             actually block those; what it DOES stop for real is the
             one case a page genuinely can detect: the OS's own
             app-switcher/recents-list preview, which is captured from
             a snapshot taken exactly on the visibilitychange/blur
             this listens for (see the script below). --}}
        <div id="one-view-hidden-guard" class="absolute inset-0 z-10 hidden flex-col items-center justify-center gap-2 bg-gray-950 text-center">
            <span class="text-3xl">&#128065;&#65039;</span>
            <p class="px-6 text-sm font-medium text-gray-300">Hidden while this window isn't in focus</p>
        </div>
        <button type="button" id="one-view-modal-close" class="absolute right-4 top-4 flex h-9 w-9 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20">&times;</button>
        {{-- Reply/React work directly from the full-screen view itself,
             not just from the thread's own actions card - matches real
             WhatsApp, where a View Once viewer isn't a dead end. --}}
        <div class="absolute bottom-6 left-1/2 flex -translate-x-1/2 items-center gap-3">
            <button type="button" id="one-view-modal-reply" class="flex items-center gap-2 rounded-full bg-white/10 px-4 py-2 text-sm font-medium text-white hover:bg-white/20">
                <span>&#8617;&#65039;</span> Reply
            </button>
            <button type="button" id="one-view-modal-react" class="flex items-center gap-2 rounded-full bg-white/10 px-4 py-2 text-sm font-medium text-white hover:bg-white/20">
                <span>&#128578;</span> React
            </button>
        </div>
    </div>

    {{-- Tap-to-expand full-screen viewer for a normal (non-One-View)
         image bubble - previously there was no viewer at all, just the
         small inline <img>. Video/audio get their own fully custom
         player controls built inline in the bubble instead (see
         createVideoPlayer()/createAudioPlayer()) rather than a
         separate full-screen step. --}}
    <div id="media-viewer-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/90 p-4">
        <img id="media-viewer-image" class="max-h-[90vh] max-w-full touch-none rounded-lg object-contain" alt="">
        {{-- Prev/Next only appear when the tapped image has other images
             in the same thread to browse to (see collectViewerImages() /
             showAdjacentImage() below) - a single image never shows a
             carousel it can't actually use. Navigation wraps around
             (last -> first, first -> last), matching the typical
             photo-viewer convention rather than dead-ending at the ends. --}}
        <button type="button" id="media-viewer-prev" class="absolute left-4 top-1/2 hidden h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20">
            <x-icons.chevron-left class="h-5 w-5" />
        </button>
        <button type="button" id="media-viewer-next" class="absolute right-4 top-1/2 hidden h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20">
            <x-icons.chevron-right class="h-5 w-5" />
        </button>
        <span id="media-viewer-counter" class="absolute bottom-4 left-1/2 hidden -translate-x-1/2 rounded-full bg-white/10 px-3 py-1 text-xs font-medium text-white"></span>
        <a id="media-viewer-download" href="#" download class="absolute right-16 top-4 flex h-9 w-9 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20">
            <x-icons.download class="h-5 w-5" />
        </a>
        <button type="button" id="media-viewer-close" class="absolute right-4 top-4 flex h-9 w-9 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20">
            <x-icons.x-circle class="h-5 w-5" />
        </button>
    </div>

    {{-- Full emoji picker - reached via the "more" button on any
         message's quick reaction strip, or React from the One View
         modal above. Recently-used emoji are remembered (localStorage)
         and shown first. --}}
    <div id="emoji-picker-modal" class="fixed inset-0 z-[60] hidden items-end justify-center bg-black/40 p-4 sm:items-center">
        <div class="flex max-h-[70vh] w-full max-w-sm flex-col rounded-2xl bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                <h2 class="text-sm font-semibold text-gray-900">Choose an emoji</h2>
                <button type="button" id="emoji-picker-close" class="rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600">&times;</button>
            </div>
            <div id="emoji-picker-body" class="flex-1 overflow-y-auto p-3"></div>
        </div>
    </div>

    {{-- In-app camera capture (Instagram/WhatsApp-style) - deliberately
         never the OS's own native camera picker. Tap the shutter for a
         photo; press and hold it to record a video, mirroring the same
         tap-vs-hold gesture those apps use. Capturing lands on a
         review screen (retake or use) rather than sending immediately -
         "use" feeds the result into the same pendingAttachments preview
         flow as a Gallery pick, so View Once/caption/Send all still
         apply normally. --}}
    <div id="camera-modal" class="fixed inset-0 z-50 hidden flex-col bg-black">
        <div class="flex items-center justify-between p-4">
            <button type="button" id="camera-close" class="flex h-9 w-9 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20">&times;</button>
            <button type="button" id="camera-flip" class="hidden h-9 w-9 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20">&#8635;</button>
        </div>
        <div class="relative flex flex-1 items-center justify-center overflow-hidden">
            <video id="camera-video" autoplay playsinline muted class="max-h-full max-w-full"></video>
            <canvas id="camera-canvas" class="hidden"></canvas>
            <div id="camera-review" class="absolute inset-0 hidden items-center justify-center bg-black">
                <img id="camera-review-img" class="hidden max-h-full max-w-full" alt="">
                <video id="camera-review-video" class="hidden max-h-full max-w-full" controls playsinline></video>
            </div>
            <div id="camera-record-indicator" class="absolute left-4 top-4 hidden items-center gap-2 rounded-full bg-black/50 px-3 py-1 text-xs font-semibold text-white">
                <span class="h-2 w-2 rounded-full bg-red-500"></span>
                <span id="camera-record-time">0:00</span>
            </div>
            {{-- WhatsApp-style hands-free recording: while holding the
                 shutter, dragging up past a threshold "locks" recording
                 on so releasing your finger doesn't stop it - a real
                 stop button takes over instead. This hint only shows
                 while actively recording and not yet locked. --}}
            <div id="camera-lock-hint" class="pointer-events-none absolute bottom-24 left-1/2 hidden -translate-x-1/2 flex-col items-center gap-1 text-white">
                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-black/50"><x-icons.lock class="h-4 w-4" /></span>
                <span class="text-[11px] font-medium drop-shadow">Slide up to lock</span>
            </div>
        </div>
        <div id="camera-capture-controls" class="flex items-center justify-center gap-10 p-6">
            <button type="button" id="camera-shutter" class="flex h-16 w-16 items-center justify-center rounded-full border-4 border-white bg-white/30" title="Tap for photo, hold for video"></button>
            {{-- Only shown once a hold-recording has been locked hands-free. --}}
            <button type="button" id="camera-stop-recording" class="hidden h-16 w-16 items-center justify-center rounded-full bg-red-600 text-white hover:bg-red-700" title="Stop recording">
                <span class="h-5 w-5 rounded bg-white"></span>
            </button>
        </div>
        <div id="camera-review-controls" class="hidden items-center justify-between gap-4 p-6">
            <button type="button" id="camera-retake" class="rounded-full bg-white/20 px-4 py-2 text-sm font-medium text-white hover:bg-white/30">Retake</button>
            <button type="button" id="camera-use" class="flex h-12 w-12 items-center justify-center rounded-full bg-brand-600 text-white hover:bg-brand-700">&#10003;</button>
        </div>
    </div>

    {{-- Sender-only "Info" view - exact delivered/read timestamps per
         recipient, reachable from a message's own action row. --}}
    <div id="message-info-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-sm rounded-2xl bg-white p-4 shadow-xl">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900">Message info</h2>
                <button type="button" id="message-info-modal-close" class="rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600">&times;</button>
            </div>
            <div id="message-info-modal-body" class="space-y-3 text-sm"></div>
        </div>
    </div>

    <script>
    // Wrapped in DOMContentLoaded because Vite's script tag is
    // type="module" (deferred by spec) - it hasn't run yet, and
    // window.axios doesn't exist yet, at the point the parser reaches
    // this plain inline script further down the page.
    document.addEventListener('DOMContentLoaded', function () {
        var conversationId = {{ (int) $conversation->id }};
        var currentUserId = {{ (int) auth()->id() }};
        var messageSelectModeActive = false;
        var pollMs = {{ (int) $pollingConfig['open_conversation_ms'] }};
        var typingTimeoutMs = {{ (int) config('chat.typing_timeout_seconds') * 1000 }};
        @if (! $group && $otherUser)
        var otherUserId = {{ (int) $otherUser->id }};
        var otherUserName = @json($otherUser->full_name);
        var voiceCallButton = document.getElementById('voice-call-button');
        var videoCallButton = document.getElementById('video-call-button');
        voiceCallButton.addEventListener('click', function () {
            window.MeetCalls.start(otherUserId, 'voice', otherUserName, conversationId);
        });
        videoCallButton.addEventListener('click', function () {
            window.MeetCalls.start(otherUserId, 'video', otherUserName, conversationId);
        });
        @endif

        // Mobile keyboard opening shouldn't make the composer/message
        // list jump around - matching WhatsApp/Instagram's smooth
        // behavior. The root cause: this container's height comes from
        // a `height: 100%` chain rooted at <html>, which only tracks
        // the *layout* viewport - opening the on-screen keyboard
        // shrinks the *visual* viewport instead, so a height:100%
        // column doesn't resize and part of it (the composer, or the
        // bottom of the message list) ends up hidden under the
        // keyboard, which the browser's own "scroll focused input into
        // view" behavior then jankily fights with. Pinning this
        // container's real pixel height to `visualViewport.height`
        // keeps the flex column (header/messages/composer) always
        // sized to what's actually visible, so the composer naturally
        // ends up sitting right above the keyboard with no jump - only
        // needed below the `lg` breakpoint (desktop already has plenty
        // of room and no on-screen keyboard to speak of).
        (function () {
            if (!window.visualViewport) return;
            var container = document.getElementById('chat-app-container');
            var mobileQuery = window.matchMedia('(max-width: 1023px)');
            // #offline-banner is a sibling ABOVE <main> (see
            // components/layouts/app.blade.php), not inside this
            // container - it wasn't accounted for here, so pinning
            // this container to the FULL visualViewport height while
            // the banner is also taking up real space at the top
            // pushed the composer off the bottom of the screen the
            // instant the connection dropped (real bug: "chat box
            // getting out of screen" on going offline). Subtracting
            // its current rendered height (0 when hidden) keeps the
            // two from ever exceeding the actual visible viewport
            // together.
            var offlineBanner = document.getElementById('offline-banner');
            // Not the shared `composerInput` var below - this IIFE runs
            // before that assignment executes, so it needs its own
            // reference to the same element rather than relying on hoisting.
            var composerInputEl = document.getElementById('composer-input');

            function syncHeight() {
                // The focus/blur retry burst below calls this several
                // times for one keyboard toggle "just in case" - skipping
                // a write when nothing actually changed avoids gratuitous
                // reflow while someone is rapidly typing/sending (each
                // send briefly blurs then refocuses the composer).
                var next;
                if (mobileQuery.matches) {
                    var bannerHeight = offlineBanner ? offlineBanner.offsetHeight : 0;
                    next = (window.visualViewport.height - bannerHeight) + 'px';
                } else {
                    next = '';
                }
                if (container.style.height !== next) {
                    container.style.height = next;
                }
            }

            // An installed Android PWA (`display-mode: standalone`) is
            // reported as still having the composer jump/get covered on
            // keyboard open, even though the identical page works fine in
            // a plain mobile browser tab - this is a real, long-standing
            // Chromium gap: `visualViewport`'s own 'resize' event is
            // unreliable inside a standalone/WebAPK window specifically,
            // sometimes never firing at all for the keyboard, unlike in a
            // normal tab. Focusing/blurring the composer directly is the
            // one signal that always fires regardless of that gap, so it
            // re-syncs imperatively rather than only waiting on a passive
            // viewport event that may never come. The keyboard's own
            // slide-in/out animation still takes a moment to finish, so
            // this reads the settled height a few times rather than once
            // immediately (which would still see the pre-keyboard size).
            // Replying (which calls composerInput.focus() directly) and
            // then attaching something in the same compose action (which
            // blurs the composer when the native file/camera picker
            // steals focus, then refocuses it on return) fire a focus
            // burst and a blur burst close together. Each burst used to
            // schedule its own 5 timers regardless of any still pending
            // from the other, so up to 10 uncoordinated writes could
            // land in quick succession, each reading whatever
            // visualViewport.height happened to be at that exact
            // moment mid-transition (keyboard opening, picker opening/
            // closing) - the container's pinned height snapping between
            // several different values is what actually reads as a
            // "zoom"/expand glitch. Clearing any still-pending burst
            // before scheduling a new one collapses overlapping bursts
            // into just the latest one.
            var syncHeightTimers = [];
            function syncHeightSoon() {
                syncHeightTimers.forEach(clearTimeout);
                syncHeightTimers = [0, 80, 160, 320, 500].map(function (delay) {
                    return setTimeout(syncHeight, delay);
                });
            }

            // The burst above only ever covers the first 500ms after
            // focus/blur - real gap still reported in the installed PWA
            // specifically: anything that changes the keyboard's actual
            // height WITHOUT a blur (GBoard's own emoji/sticker picker,
            // a suggestion/clipboard strip appearing, rotating the
            // device while the keyboard is still up) never re-triggers
            // this at all once the burst has finished, since nothing
            // else is listening for it in a context where
            // visualViewport's own resize/scroll events are exactly the
            // signal already documented above as unreliable. Polling
            // for as long as the composer actually has focus - not just
            // a fixed window right after it gained focus - covers that
            // regardless of what caused the height change.
            var focusedSyncInterval = null;
            function startFocusedSync() {
                clearInterval(focusedSyncInterval);
                focusedSyncInterval = setInterval(syncHeight, 250);
            }
            function stopFocusedSync() {
                clearInterval(focusedSyncInterval);
                focusedSyncInterval = null;
            }

            window.visualViewport.addEventListener('resize', syncHeight);
            window.visualViewport.addEventListener('scroll', syncHeight);
            mobileQuery.addEventListener('change', syncHeight);
            window.addEventListener('resize', syncHeight);
            window.addEventListener('offline', function () { setTimeout(syncHeight, 0); });
            window.addEventListener('online', function () { setTimeout(syncHeight, 0); });
            if (composerInputEl) {
                composerInputEl.addEventListener('focus', function () {
                    syncHeightSoon();
                    startFocusedSync();
                });
                composerInputEl.addEventListener('blur', function () {
                    syncHeightSoon();
                    stopFocusedSync();
                });
            }
            syncHeight();
        })();

        (function () {
            var menuBtn = document.getElementById('chat-menu-btn');
            var dropdown = document.getElementById('chat-menu-dropdown');
            var wallpaperBtn = document.getElementById('chat-wallpaper-btn');
            var picker = document.getElementById('wallpaper-picker');
            var swatchesEl = document.getElementById('wallpaper-swatches');
            var conversationId = document.getElementById('messages').dataset.conversationId;
            var swatchesLoaded = false;

            menuBtn.addEventListener('click', function (event) {
                event.stopPropagation();
                dropdown.classList.toggle('hidden');
            });
            document.addEventListener('click', function () {
                dropdown.classList.add('hidden');
            });

            function applyWallpaper(value) {
                document.getElementById('messages').style.backgroundColor = value || '';
            }

            function loadWallpapers() {
                axios.get('/api/v1/conversations/' + conversationId + '/wallpaper').then(function (response) {
                    var data = response.data.data;
                    swatchesEl.innerHTML = '';

                    data.available.forEach(function (wallpaper) {
                        var swatch = document.createElement('button');
                        swatch.type = 'button';
                        swatch.title = wallpaper.name;
                        swatch.className = 'h-8 w-8 rounded-full border-2 ' +
                            (wallpaper.id === data.current_wallpaper_id ? 'border-brand-600' : 'border-gray-200');
                        swatch.style.backgroundColor = wallpaper.value;
                        swatch.addEventListener('click', function () {
                            axios.patch('/api/v1/conversations/' + conversationId + '/settings', { wallpaper_id: wallpaper.id }).then(function () {
                                applyWallpaper(wallpaper.value);
                                picker.classList.add('hidden');
                                swatchesLoaded = false;
                            });
                        });
                        swatchesEl.appendChild(swatch);
                    });

                    if (data.current_wallpaper_id) {
                        var current = data.available.find(function (w) { return w.id === data.current_wallpaper_id; });
                        if (current) applyWallpaper(current.value);
                    }

                    swatchesLoaded = true;
                });
            }

            wallpaperBtn.addEventListener('click', function (event) {
                event.stopPropagation();
                dropdown.classList.add('hidden');
                picker.classList.toggle('hidden');
                if (!swatchesLoaded) loadWallpapers();
            });

            // Apply the saved wallpaper on load without opening the picker.
            axios.get('/api/v1/conversations/' + conversationId + '/wallpaper').then(function (response) {
                var data = response.data.data;
                var current = data.available.find(function (w) { return w.id === data.current_wallpaper_id; });
                if (current) applyWallpaper(current.value);
            });
        })();

        // Shared with updateDisappearingIndicator() below, which is why
        // this lives outside the menu IIFE - the header badge needs the
        // same duration->label mapping the picker itself uses, and both
        // pollConversationMeta() (a plain top-level function) and this
        // IIFE need to reach it.
        var DISAPPEARING_OPTIONS = [
            { duration: 'off', label: 'Off', seconds: null },
            { duration: '24_hours', label: '24 hours', seconds: 86400 },
            { duration: '7_days', label: '7 days', seconds: 604800 },
            { duration: '90_days', label: '90 days', seconds: 7776000 },
            { duration: 'after_seen', label: 'After being seen', seconds: 0 },
        ];

        // The only lasting, visible state of whether disappearing
        // messages are on used to be the dataset attribute on the
        // (usually closed) picker panel itself - nothing stayed on
        // screen to show it. Called on load, right after picking an
        // option (this device), and every pollConversationMeta() tick
        // (the other participant's device).
        function updateDisappearingIndicator(seconds) {
            var indicator = document.getElementById('disappearing-indicator');
            var labelEl = document.getElementById('disappearing-indicator-label');
            if (!indicator || !labelEl) {
                return;
            }
            if (seconds === null || seconds === undefined) {
                indicator.classList.add('hidden');
                indicator.classList.remove('inline-flex');
                return;
            }
            var match = DISAPPEARING_OPTIONS.find(function (o) { return o.seconds === seconds; });
            labelEl.textContent = match ? match.label : (seconds + 's');
            indicator.classList.remove('hidden');
            indicator.classList.add('inline-flex');
        }

        (function () {
            var menuBtn = document.getElementById('chat-menu-btn');
            var dropdown = document.getElementById('chat-menu-dropdown');
            var disappearingBtn = document.getElementById('chat-disappearing-btn');
            var picker = document.getElementById('disappearing-picker');
            var optionsEl = document.getElementById('disappearing-options');
            var conversationId = document.getElementById('messages').dataset.conversationId;

            function currentSeconds() {
                var raw = picker.dataset.disappearingSeconds;
                return raw ? parseInt(raw, 10) : null;
            }

            updateDisappearingIndicator(currentSeconds());

            function renderOptions() {
                var active = currentSeconds();
                optionsEl.innerHTML = '';

                DISAPPEARING_OPTIONS.forEach(function (option) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.textContent = option.label;
                    var isActive = option.seconds === active;
                    btn.className = 'rounded-lg border px-3 py-1.5 text-xs font-medium ' +
                        (isActive ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-600 hover:bg-gray-50');
                    btn.addEventListener('click', function () {
                        axios.patch('/api/v1/conversations/' + conversationId + '/disappearing-messages', { duration: option.duration }).then(function (response) {
                            // `|| ''` would also catch a real 0 ("after
                            // being seen") and blank it out, making that
                            // option look deselected again immediately
                            // after picking it (until the next full page
                            // load re-reads the correct value from the
                            // server-rendered dataset attribute) - only
                            // null/undefined should map to the "off" ('')
                            // sentinel here.
                            var seconds = response.data.data.disappearing_messages_seconds;
                            picker.dataset.disappearingSeconds = (seconds === null || seconds === undefined) ? '' : String(seconds);
                            updateDisappearingIndicator(seconds);
                            renderOptions();
                            picker.classList.add('hidden');
                        });
                    });
                    optionsEl.appendChild(btn);
                });
            }

            disappearingBtn.addEventListener('click', function (event) {
                event.stopPropagation();
                dropdown.classList.add('hidden');
                picker.classList.toggle('hidden');
                renderOptions();
            });
        })();

        var REACTION_EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

        var messagesEl = document.getElementById('messages');
        var typingEl = document.getElementById('typing-indicator');
        var composerForm = document.getElementById('composer');
        var composerInput = document.getElementById('composer-input');
        var errorEl = document.getElementById('chat-error');
        var replyBanner = document.getElementById('reply-banner');
        var replyBannerText = document.getElementById('reply-banner-text');
        var replyBannerCancel = document.getElementById('reply-banner-cancel');
        var pinnedBanner = document.getElementById('pinned-banner');
        var pinnedBannerText = document.getElementById('pinned-banner-text');
        var pinnedBannerUnpin = document.getElementById('pinned-banner-unpin');
        var currentPinnedMessageId = null;
        var forwardPicker = document.getElementById('forward-picker');
        var attachmentInputGallery = document.getElementById('attachment-input-gallery');
        var attachmentInputDocument = document.getElementById('attachment-input-document');
        var attachmentButton = document.getElementById('attachment-button');
        var attachmentMenu = document.getElementById('attachment-menu');
        var attachmentMenuCamera = document.getElementById('attachment-menu-camera');
        var attachmentMenuGallery = document.getElementById('attachment-menu-gallery');
        var attachmentMenuDocument = document.getElementById('attachment-menu-document');
        var attachmentPreview = document.getElementById('attachment-preview');
        var attachmentPreviewText = document.getElementById('attachment-preview-text');
        var attachmentPreviewThumbs = document.getElementById('attachment-preview-thumbs');
        var attachmentCancel = document.getElementById('attachment-cancel');
        var oneViewToggle = document.getElementById('one-view-toggle');
        var voiceNoteButton = document.getElementById('voice-note-button');
        var composerNormalRow = document.getElementById('composer-normal-row');
        var voiceRecordingBar = document.getElementById('voice-recording-bar');
        var voiceRecordingCancel = document.getElementById('voice-recording-cancel');
        var voiceRecordingPause = document.getElementById('voice-recording-pause');
        var voiceRecordingSend = document.getElementById('voice-recording-send');
        var voiceRecordingTimeEl = document.getElementById('voice-recording-time');
        var voiceRecordingDot = document.getElementById('voice-recording-dot');
        var voiceWaveform = document.getElementById('voice-waveform');
        var voiceRecordingPill = document.getElementById('voice-recording-pill');
        var voiceSlideCancelHint = document.getElementById('voice-slide-cancel-hint');
        var sendBtn = composerForm.querySelector('button[type="submit"]');
        sendBtn.disabled = true; // nothing typed yet - re-enabled by the input listener below

        // WhatsApp-style: only one of Voice/Send is ever visible, never
        // both side by side - Voice by default, swapping to Send the
        // instant there's something to send (text typed or an attachment
        // queued), and back the moment the composer empties out again.
        // A MutationObserver on sendBtn's own `disabled` attribute - which
        // every "is there anything to send" check already keeps correct
        // (see composerInput's input listener, attachment/reply flows,
        // and the post-send reset) - means this stays right automatically
        // instead of needing its own copy of that same logic.
        function updateSendVoiceToggle() {
            var hasContent = !sendBtn.disabled;
            sendBtn.classList.toggle('hidden', !hasContent);
            voiceNoteButton.classList.toggle('hidden', hasContent);
        }
        new MutationObserver(updateSendVoiceToggle).observe(sendBtn, { attributes: true, attributeFilter: ['disabled'] });
        updateSendVoiceToggle();

        // Pagination state for scrolling UP through older history - see
        // loadOlderMessages(). Reset whenever loadMessages() rebuilds the
        // thread from scratch (a fresh page load/reconnect).
        var oldestLoadedMessageId = null;
        var noMoreOlderMessages = false;
        var loadingOlderMessages = false;

        var lastMessageId = 0;
        // Earliest of MY OWN messages currently rendered - the poll
        // that advances already-shown ticks (sent -> delivered -> read)
        // only needs to ask about messages from here onward, not the
        // whole conversation history.
        var earliestMyMessageId = null;
        // Current reaction list per rendered message id - the source
        // renderMessage()/reactToMessage()/removeReactionFromMessage()/
        // syncReactions() all read from and write back to, so every one
        // of them is patching the same picture of "who reacted with
        // what" rather than three separate partial views of it.
        var messageReactionsMap = {};
        var typingSendTimer = null;
        var typingStopTimer = null;
        var replyingToId = null;
        var forwardingMessageIds = [];
        var pendingAttachments = []; // [{ file, isVoiceNote, durationSeconds }] - voice notes are always exactly one
        var isSending = false;
        var swipeStartX = null;
        var swipeStartY = null;
        var justSwiped = false;
        var mediaRecorder = null;
        var recordedChunks = [];
        var replyingToBody = null;

        // --- End-to-end encryption -------------------------------------
        // Scoped to text message bodies only for now - attachments (and
        // View Once, which stays server-decryptable on purpose so the
        // existing archive-copy feature keeps working) are unaffected
        // and still travel exactly as before. The server holds every
        // conversation key itself and transparently issues/rotates
        // epochs as needed (see ConversationEncryptionService::status())
        // - there's no per-user identity to unlock, so `window.MeetE2EReady`
        // (see the app layout) resolves to a plain true/false: whether
        // this browser can actually do the WebCrypto/IndexedDB work at
        // all, not whether some identity is "unlocked."
        var conversationKeyCache = {}; // "<conversationId>:<version>" -> CryptoKey (per-tab; e2e-crypto.js persists the raw material to IndexedDB too)
        var e2eIndicator = document.getElementById('e2e-indicator');

        function e2eAvailable() {
            return window.MeetE2EReady || Promise.resolve(false);
        }

        function getConversationKeyForVersion(targetConversationId, version) {
            var cacheKey = targetConversationId + ':' + version;
            if (conversationKeyCache[cacheKey]) {
                return Promise.resolve(conversationKeyCache[cacheKey]);
            }
            return e2eAvailable().then(function (available) {
                if (!available) return null;
                return MeetE2E.getConversationKey(targetConversationId, version).then(function (key) {
                    if (key) conversationKeyCache[cacheKey] = key;
                    return key;
                }).catch(function () { return null; });
            });
        }

        // The one entry point for "give me a usable AES key to encrypt
        // with right now" - the server transparently issues/rotates the
        // conversation's key epoch as needed; this just asks for
        // whatever epoch is current and fetches/caches its key
        // material. Resolves null (meaning: don't encrypt this send)
        // only when encryption genuinely isn't possible right now - too
        // many members, or this browser lacks WebCrypto/IndexedDB.
        function ensureEncryptionReadyFor(targetConversationId) {
            return e2eAvailable().then(function (available) {
                if (!available) return null;

                return axios.get('/api/v1/conversations/' + targetConversationId + '/encryption').then(function (response) {
                    var status = response.data.data;
                    if (targetConversationId === conversationId) updateE2EIndicator(status);
                    if (!status.eligible || !status.current_key_version) return null;

                    return getConversationKeyForVersion(targetConversationId, status.current_key_version).then(function (key) {
                        return key ? { key: key, version: status.current_key_version } : null;
                    });
                }).catch(function (error) {
                    console.error('Failed to prepare end-to-end encryption', error);
                    return null;
                });
            });
        }

        function updateE2EIndicator(status) {
            if (!e2eIndicator) return;
            if (!status.eligible) {
                e2eIndicator.textContent = 'Not end-to-end encrypted (too many members)';
                e2eIndicator.classList.remove('hidden', 'text-emerald-600');
                e2eIndicator.classList.add('text-gray-400');
            } else if (status.e2e_enabled) {
                e2eIndicator.textContent = 'End-to-end encrypted';
                e2eIndicator.classList.remove('hidden', 'text-gray-400');
                e2eIndicator.classList.add('text-emerald-600');
            } else {
                e2eIndicator.classList.add('hidden');
            }
        }

        // Catches up an existing (pre-E2E) conversation's plaintext
        // history once it's actually eligible - pages backward through
        // the whole thread, encrypts every still-plaintext text message
        // under the conversation's current key, and PATCHes each page
        // back as ciphertext. Runs once per page load, so a very long
        // history catches up over several opens rather than one huge
        // blocking pass - see MigrateMessagesToEncryptedRequest's
        // per-call cap.
        function migrateHistoryToEncrypted(keyInfo) {
            var oldestSeenId = null;

            function nextPage() {
                var params = oldestSeenId ? { before: oldestSeenId } : {};
                return axios.get('/api/v1/messages/' + conversationId, { params: params }).then(function (response) {
                    var page = response.data.data;
                    if (page.length === 0) {
                        return;
                    }
                    oldestSeenId = page[0].id;

                    var plaintextTextMessages = page.filter(function (m) {
                        return m.encryption_mode === 'none' && m.message_type === 'text' && m.body;
                    });

                    if (plaintextTextMessages.length === 0) {
                        return page.length < 30 ? undefined : nextPage();
                    }

                    return Promise.all(plaintextTextMessages.map(function (m) {
                        return MeetE2E.encryptText(m.body, keyInfo.key).then(function (enc) {
                            return { message_id: m.id, body: enc.body, iv: enc.iv };
                        });
                    })).then(function (encrypted) {
                        return axios.post('/api/v1/conversations/' + conversationId + '/messages/migrate-to-encrypted', {
                            key_version: keyInfo.version,
                            messages: encrypted,
                        });
                    }).then(function () {
                        return page.length < 30 ? undefined : nextPage();
                    });
                });
            }

            nextPage().catch(function (error) {
                console.error('Failed to migrate historical messages to encrypted', error);
            });
        }

        function decryptMessageInPlace(message) {
            if (message.encryption_mode !== 'e2e') {
                return Promise.resolve(message);
            }
            return getConversationKeyForVersion(conversationId, message.key_version).then(function (key) {
                if (!key) {
                    message.body = '🔒 Encrypted message (unable to decrypt on this device)';
                    return message;
                }
                return MeetE2E.decryptText(message.body, message.iv, key).then(function (plaintext) {
                    message.body = plaintext;
                    if (message.reply_to && message.reply_to.preview_iv) {
                        return MeetE2E.decryptText(message.reply_to.preview, message.reply_to.preview_iv, key)
                            .then(function (previewPlain) { message.reply_to.preview = previewPlain; })
                            .catch(function () { message.reply_to.preview = '🔒 Encrypted message'; })
                            .then(function () { return message; });
                    }
                    return message;
                }).catch(function () {
                    message.body = '🔒 Encrypted message (unable to decrypt on this device)';
                    return message;
                });
            });
        }
        // -----------------------------------------------------------------

        function showError(message) {
            errorEl.textContent = message;
            errorEl.classList.remove('hidden');
        }

        function clearError() {
            errorEl.classList.add('hidden');
        }

        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }

        // Matches escapeHtml()'s own trailing-punctuation trimming isn't
        // needed here - this runs on the RAW (pre-escape) text and hands
        // back already-safe HTML, escaping the plain-text segments and
        // the link text/href separately, so a URL's own "&" (a query
        // string separator) can never collide with escapeHtml() having
        // already turned it into "&amp;" before this regex ran.
        var URL_REGEX = /https?:\/\/[^\s<]+[^\s<.,;:!?'")\]]/gi;

        function linkifyHtml(rawText) {
            var text = rawText || '';
            var html = '';
            var lastIndex = 0;
            var match;
            URL_REGEX.lastIndex = 0;
            while ((match = URL_REGEX.exec(text)) !== null) {
                html += escapeHtml(text.slice(lastIndex, match.index));
                var url = match[0];
                var safe = escapeHtml(url);
                // break-all (not the default word-wrap) so one long,
                // space-free URL can't overflow the message bubble - the
                // reported "long URL runs outside the bubble" bug.
                html += '<a href="' + safe + '" target="_blank" rel="noopener noreferrer" class="break-all underline">' + safe + '</a>';
                lastIndex = match.index + url.length;
            }
            html += escapeHtml(text.slice(lastIndex));
            return html;
        }

        function firstUrl(rawText) {
            URL_REGEX.lastIndex = 0;
            var match = URL_REGEX.exec(rawText || '');
            return match ? match[0] : null;
        }

        // One preview per URL for this page's lifetime - every message
        // referencing the same link (or a re-render/poll re-fetching the
        // same message) reuses it instead of hitting the endpoint again.
        var linkPreviewCache = {};

        function fetchLinkPreview(url, bubble) {
            if (Object.prototype.hasOwnProperty.call(linkPreviewCache, url)) {
                renderLinkPreviewCard(bubble, linkPreviewCache[url]);
                return;
            }
            axios.get('/api/v1/link-preview', { params: { url: url } }).then(function (response) {
                linkPreviewCache[url] = response.data.data;
                renderLinkPreviewCard(bubble, response.data.data);
            }).catch(function () {
                // No preview available (private/unreachable URL, no
                // og:title, network hiccup, etc.) - the link itself
                // still works fine, this is purely additive.
            });
        }

        function renderLinkPreviewCard(bubble, preview) {
            if (!preview || !bubble.isConnected || bubble.querySelector('.link-preview-card')) {
                return;
            }
            var card = document.createElement('a');
            card.href = preview.url;
            card.target = '_blank';
            card.rel = 'noopener noreferrer';
            card.className = 'link-preview-card mt-2 block overflow-hidden rounded-lg border border-black/10 bg-white text-gray-900';
            var html = '';
            if (preview.image) {
                html += '<img src="' + escapeHtml(preview.image) + '" class="h-28 w-full object-cover" loading="lazy" alt="">';
            }
            html += '<div class="p-2">';
            html += '<p class="truncate text-xs font-semibold">' + escapeHtml(preview.title) + '</p>';
            if (preview.description) {
                html += '<p class="line-clamp-2 text-[11px] text-gray-500">' + escapeHtml(preview.description) + '</p>';
            }
            html += '</div>';
            card.innerHTML = html;
            bubble.appendChild(card);
        }

        function formatMessageTime(iso) {
            if (!iso) return '';
            var d = new Date(iso);
            var h = d.getHours();
            var m = d.getMinutes();
            var ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12;
            if (h === 0) h = 12;
            return h + ':' + (m < 10 ? '0' : '') + m + ' ' + ampm;
        }

        // A single tick means "sent"; a second tick means "delivered".
        // For "read" the ticks aren't just recolored WhatsApp-blue - a
        // deliberately different amber/gold fill so it reads as this
        // app's own signal rather than a copy of WhatsApp's or
        // Instagram's own seen-indicator.
        var TICK_PATH = 'M15.01 3.316l-.478-.372a.365.365 0 0 0-.51.063L8.666 9.879a.32.32 0 0 1-.484.033l-.358-.325a.319.319 0 0 0-.484.032l-.378.483a.418.418 0 0 0 .036.541l1.32 1.266c.143.14.361.125.484-.033l6.272-8.048a.366.366 0 0 0-.064-.512z';
        var TICK_PATH_2 = 'M11.01 3.316l-.478-.372a.365.365 0 0 0-.51.063L4.666 9.879a.32.32 0 0 1-.484.033l-.358-.325a.319.319 0 0 0-.484.032l-.378.483a.418.418 0 0 0 .036.541l1.32 1.266c.143.14.361.125.484-.033l6.272-8.048a.366.366 0 0 0-.064-.512z';

        function tickIconHtml(status) {
            var doubleTick = status === 'delivered' || status === 'read';
            var colorClass = status === 'read' ? 'text-amber-300' : 'text-white/70';
            var paths = '<path d="' + TICK_PATH + '"></path>' + (doubleTick ? '<path d="' + TICK_PATH_2 + '"></path>' : '');
            return '<svg viewBox="0 0 16 15" class="h-3 w-3 ' + colorClass + '" fill="currentColor" aria-hidden="true">' + paths + '</svg>';
        }

        function renderTick(el, status) {
            el.innerHTML = tickIconHtml(status || 'sent');
            el.dataset.status = status || 'sent';
        }

// Fully custom-built playback UI, never a native <video controls>/
// <audio controls> element - both still wrap a real <video>/<audio>
// underneath (needed for autoplay/'ended' etc. - see
// viewOneViewMedia() below, which reaches it via el._mediaEl), just
// with every visible control (play/pause, seek, time, volume,
// fullscreen) built and driven by this app's own JS instead of the
// browser's built-in chrome.
var PLAYER_ICON = {
    play: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.5v13l11-6.5-11-6.5z"></path></svg>',
    pause: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M7 5h4v14H7zM13 5h4v14h-4z"></path></svg>',
    volume: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9v6h4l5 4V5L8 9H4z"></path><path d="M16.5 8.5a5 5 0 0 1 0 7"></path></svg>',
    volumeOff: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9v6h4l5 4V5L8 9H4z"></path><path d="m16 9 5 6m0-6-5 6"></path></svg>',
    fullscreen: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 4H4v4M16 4h4v4M8 20H4v-4M16 20h4v-4"></path></svg>',
};

function formatPlayerTime(seconds) {
    if (!isFinite(seconds) || seconds < 0) seconds = 0;
    var mins = Math.floor(seconds / 60);
    var secs = Math.floor(seconds % 60);
    return mins + ':' + (secs < 10 ? '0' : '') + secs;
}

// WhatsApp-style compact voice-note/audio bar: play/pause, a seek
// slider, and a time label - deliberately no volume control, matching
// the reference UI exactly.
function createAudioPlayer(url) {
    var wrap = document.createElement('div');
    wrap.className = 'custom-audio-player flex w-56 max-w-full items-center gap-2 rounded-full bg-black/5 px-3 py-2';

    var audio = document.createElement('audio');
    audio.preload = 'metadata';
    audio.src = url;
    audio.className = 'hidden';

    var playBtn = document.createElement('button');
    playBtn.type = 'button';
    playBtn.className = 'flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-600 text-white';
    playBtn.innerHTML = PLAYER_ICON.play;

    var seek = document.createElement('input');
    seek.type = 'range';
    seek.className = 'player-seek min-w-0 flex-1';
    seek.min = '0';
    seek.max = '100';
    seek.value = '0';

    var time = document.createElement('span');
    time.className = 'w-9 shrink-0 text-right text-xs tabular-nums text-gray-500';
    time.textContent = '0:00';

    var seeking = false;

    playBtn.addEventListener('click', function () {
        if (audio.paused) audio.play(); else audio.pause();
    });
    audio.addEventListener('play', function () { playBtn.innerHTML = PLAYER_ICON.pause; });
    audio.addEventListener('pause', function () { playBtn.innerHTML = PLAYER_ICON.play; });
    audio.addEventListener('ended', function () { playBtn.innerHTML = PLAYER_ICON.play; seek.value = '0'; });
    audio.addEventListener('loadedmetadata', function () { time.textContent = formatPlayerTime(audio.duration); });
    audio.addEventListener('timeupdate', function () {
        if (seeking || !audio.duration) return;
        seek.value = String((audio.currentTime / audio.duration) * 100);
        time.textContent = formatPlayerTime(audio.duration - audio.currentTime);
    });
    seek.addEventListener('input', function () {
        seeking = true;
        if (audio.duration) time.textContent = formatPlayerTime(audio.duration * (seek.value / 100) );
    });
    seek.addEventListener('change', function () {
        if (audio.duration) audio.currentTime = audio.duration * (seek.value / 100);
        seeking = false;
    });

    wrap.appendChild(playBtn);
    wrap.appendChild(seek);
    wrap.appendChild(time);
    wrap._mediaEl = audio;
    wrap.appendChild(audio);
    return wrap;
}

function createVideoPlayer(url) {
    var wrap = document.createElement('div');
    wrap.className = 'custom-video-player relative w-full max-w-full overflow-hidden rounded-lg bg-black';

    var video = document.createElement('video');
    video.src = url;
    video.playsInline = true;
    video.className = 'block max-h-80 w-full';

    var bigPlay = document.createElement('button');
    bigPlay.type = 'button';
    bigPlay.className = 'absolute inset-0 flex items-center justify-center bg-black/10';
    bigPlay.innerHTML = '<span class="flex h-14 w-14 items-center justify-center rounded-full bg-white/90 text-gray-900">' + PLAYER_ICON.play + '</span>';

    var bar = document.createElement('div');
    bar.className = 'absolute inset-x-0 bottom-0 flex items-center gap-2 bg-gradient-to-t from-black/70 to-transparent px-2 py-1.5';

    var playBtn = document.createElement('button');
    playBtn.type = 'button';
    playBtn.className = 'flex h-6 w-6 shrink-0 items-center justify-center text-white';
    playBtn.innerHTML = PLAYER_ICON.play;

    var seek = document.createElement('input');
    seek.type = 'range';
    seek.className = 'player-seek player-seek--video min-w-0 flex-1';
    seek.min = '0';
    seek.max = '100';
    seek.value = '0';

    var time = document.createElement('span');
    time.className = 'w-16 shrink-0 text-center text-xs tabular-nums text-white';
    time.textContent = '0:00';

    var muteBtn = document.createElement('button');
    muteBtn.type = 'button';
    muteBtn.className = 'flex h-6 w-6 shrink-0 items-center justify-center text-white';
    muteBtn.innerHTML = PLAYER_ICON.volume;

    var fullscreenBtn = document.createElement('button');
    fullscreenBtn.type = 'button';
    fullscreenBtn.className = 'flex h-6 w-6 shrink-0 items-center justify-center text-white';
    fullscreenBtn.innerHTML = PLAYER_ICON.fullscreen;

    var seeking = false;

    function togglePlay() {
        if (video.paused) video.play(); else video.pause();
    }

    bigPlay.addEventListener('click', togglePlay);
    playBtn.addEventListener('click', togglePlay);
    video.addEventListener('play', function () {
        playBtn.innerHTML = PLAYER_ICON.pause;
        bigPlay.classList.add('hidden');
    });
    video.addEventListener('pause', function () {
        playBtn.innerHTML = PLAYER_ICON.play;
        bigPlay.classList.remove('hidden');
    });
    video.addEventListener('ended', function () { bigPlay.classList.remove('hidden'); });
    video.addEventListener('loadedmetadata', function () { time.textContent = formatPlayerTime(video.duration); });
    video.addEventListener('timeupdate', function () {
        if (seeking || !video.duration) return;
        seek.value = String((video.currentTime / video.duration) * 100);
        time.textContent = formatPlayerTime(video.duration - video.currentTime);
    });
    seek.addEventListener('input', function () {
        seeking = true;
        if (video.duration) time.textContent = formatPlayerTime(video.duration * (seek.value / 100));
    });
    seek.addEventListener('change', function () {
        if (video.duration) video.currentTime = video.duration * (seek.value / 100);
        seeking = false;
    });
    muteBtn.addEventListener('click', function () {
        video.muted = !video.muted;
        muteBtn.innerHTML = video.muted ? PLAYER_ICON.volumeOff : PLAYER_ICON.volume;
    });
    fullscreenBtn.addEventListener('click', function () {
        if (wrap.requestFullscreen) wrap.requestFullscreen().catch(function () {});
    });

    bar.appendChild(playBtn);
    bar.appendChild(seek);
    bar.appendChild(time);
    bar.appendChild(muteBtn);
    bar.appendChild(fullscreenBtn);

    wrap.appendChild(video);
    wrap.appendChild(bigPlay);
    wrap.appendChild(bar);
    wrap._mediaEl = video;
    return wrap;
}

var mediaViewerModal = document.getElementById('media-viewer-modal');
var mediaViewerImage = document.getElementById('media-viewer-image');
var mediaViewerDownload = document.getElementById('media-viewer-download');
var mediaViewerPrev = document.getElementById('media-viewer-prev');
var mediaViewerNext = document.getElementById('media-viewer-next');
var mediaViewerCounter = document.getElementById('media-viewer-counter');

// There's no server-side "batch" concept for a multi-select attach -
// each picked file becomes its own independent message, uploaded and
// sent one at a time (see the composer submit handler), with nothing
// tying them back together as a group. So rather than invent a
// grouping id, the viewer just treats "the images in this thread" as
// one continuous list: whatever <img> bubbles are actually rendered
// in #messages, in on-screen order, at the moment the viewer opens.
// That's simplest and also more correct than a strict "same batch"
// carousel - it lets Next/Previous browse every image in the
// conversation, not just the ones sent in the same multi-select.
var mediaViewerList = [];
var mediaViewerIndex = -1;
var mediaViewerSwipeStartX = null;
var mediaViewerSwipeStartY = null;
var MEDIA_VIEWER_SWIPE_THRESHOLD_PX = 50;

// Real gap: Next/Previous existed, but nothing let you actually make
// an image bigger/smaller inside the viewer - the global viewport meta
// (maximum-scale=1, user-scalable=no) that stops the whole PAGE from
// pinch-zooming applies here too, so this has to be a fully custom,
// hand-rolled zoom rather than leaning on the browser's own gesture.
var mediaViewerZoomScale = 1;
var mediaViewerPanX = 0;
var mediaViewerPanY = 0;
var MEDIA_VIEWER_MAX_ZOOM = 4;
var mediaViewerPinchStartDistance = null;
var mediaViewerPinchStartScale = 1;
var mediaViewerPanStart = null; // {x, y, panX, panY} - single-touch drag, only while already zoomed in
var mediaViewerLastTapAt = 0;

function applyMediaViewerZoomTransform() {
    mediaViewerImage.style.transform = mediaViewerZoomScale === 1
        ? ''
        : 'translate(' + mediaViewerPanX + 'px, ' + mediaViewerPanY + 'px) scale(' + mediaViewerZoomScale + ')';
}

// offsetWidth/Height (layout size) rather than getBoundingClientRect()
// (which already reflects the CSS transform being applied) - otherwise
// this would be measuring the very thing it's trying to compute a
// bound for.
function clampMediaViewerPan() {
    var maxX = Math.max(0, (mediaViewerImage.offsetWidth * (mediaViewerZoomScale - 1)) / 2);
    var maxY = Math.max(0, (mediaViewerImage.offsetHeight * (mediaViewerZoomScale - 1)) / 2);
    mediaViewerPanX = Math.max(-maxX, Math.min(maxX, mediaViewerPanX));
    mediaViewerPanY = Math.max(-maxY, Math.min(maxY, mediaViewerPanY));
}

function setMediaViewerZoom(scale) {
    mediaViewerZoomScale = Math.max(1, Math.min(MEDIA_VIEWER_MAX_ZOOM, scale));
    if (mediaViewerZoomScale === 1) {
        mediaViewerPanX = 0;
        mediaViewerPanY = 0;
    } else {
        clampMediaViewerPan();
    }
    applyMediaViewerZoomTransform();
}

// Navigating to a different image (or closing) at some zoom level
// other than 1x would otherwise leave the NEXT image opening already
// zoomed/panned, which has nothing to do with whatever zoom the
// PREVIOUS image was left at.
function resetMediaViewerZoom() {
    setMediaViewerZoom(1);
}

function touchPairDistance(touches) {
    var dx = touches[0].clientX - touches[1].clientX;
    var dy = touches[0].clientY - touches[1].clientY;
    return Math.sqrt(dx * dx + dy * dy);
}

function collectViewerImages() {
    return Array.prototype.slice.call(messagesEl.querySelectorAll('img.cursor-pointer'));
}

function updateMediaViewerNavUI() {
    var total = mediaViewerList.length;
    var hasMultiple = total > 1;

    mediaViewerPrev.classList.toggle('hidden', !hasMultiple);
    mediaViewerPrev.classList.toggle('flex', hasMultiple);
    mediaViewerNext.classList.toggle('hidden', !hasMultiple);
    mediaViewerNext.classList.toggle('flex', hasMultiple);

    if (hasMultiple && mediaViewerIndex >= 0) {
        mediaViewerCounter.textContent = (mediaViewerIndex + 1) + ' / ' + total;
        mediaViewerCounter.classList.remove('hidden');
    } else {
        mediaViewerCounter.classList.add('hidden');
    }
}

function showMediaViewerIndex(index) {
    if (!mediaViewerList.length) {
        return;
    }
    mediaViewerIndex = index;
    var url = mediaViewerList[index].src;
    mediaViewerImage.src = url;
    mediaViewerDownload.href = url;
    resetMediaViewerZoom();
    updateMediaViewerNavUI();
}

// Wraps around at both ends (last image's Next goes back to the
// first, first image's Previous goes to the last) - the typical
// behavior for a photo viewer/carousel, rather than dead-ending.
function showAdjacentMediaViewerImage(direction) {
    if (mediaViewerList.length < 2 || mediaViewerIndex < 0) {
        return;
    }
    var total = mediaViewerList.length;
    showMediaViewerIndex((mediaViewerIndex + direction + total) % total);
}

function openImageViewer(url, imgEl) {
    mediaViewerList = collectViewerImages();
    var index = imgEl ? mediaViewerList.indexOf(imgEl) : -1;

    mediaViewerModal.classList.remove('hidden');
    mediaViewerModal.classList.add('flex');

    if (index >= 0) {
        showMediaViewerIndex(index);
    } else {
        // Fallback for any caller that doesn't have the element handy
        // (shouldn't normally happen, but don't leave the viewer with
        // no image over a missing index) - shows the requested image
        // without carousel navigation.
        mediaViewerIndex = -1;
        mediaViewerImage.src = url;
        mediaViewerDownload.href = url;
        resetMediaViewerZoom();
        updateMediaViewerNavUI();
    }
}

// Real gap fixed here: selecting several images to send had no preview
// step at all beyond the tiny 56px thumbnail strip - tapping one now
// opens the exact same full-size viewer/carousel as an already-sent
// image, just fed this batch's own pending thumbnails (composePreviewImages,
// rebuilt every render in renderAttachmentPreviews()) instead of the
// thread's. The <img> elements already point at the full-resolution
// file's own object URL (only their CSS size is a small thumbnail), so
// nothing needs re-reading from disk to show it larger here.
function openComposeImagePreview(imgEl) {
    mediaViewerList = composePreviewImages.slice();
    var index = mediaViewerList.indexOf(imgEl);

    mediaViewerModal.classList.remove('hidden');
    mediaViewerModal.classList.add('flex');
    showMediaViewerIndex(index >= 0 ? index : 0);
}

function closeImageViewer() {
    mediaViewerModal.classList.add('hidden');
    mediaViewerModal.classList.remove('flex');
    mediaViewerImage.src = '';
    mediaViewerList = [];
    mediaViewerIndex = -1;
    resetMediaViewerZoom();
}

document.getElementById('media-viewer-close').addEventListener('click', closeImageViewer);
mediaViewerModal.addEventListener('click', function (event) {
    if (event.target === mediaViewerModal) closeImageViewer();
});
mediaViewerPrev.addEventListener('click', function () { showAdjacentMediaViewerImage(-1); });
mediaViewerNext.addEventListener('click', function () { showAdjacentMediaViewerImage(1); });

// Swipe-left/right through the carousel (only while at 1x zoom - once
// zoomed in, a single-touch drag pans the image instead, see touchmove
// below), mirroring the same touchstart/touchmove-threshold/touchend
// pattern used for the swipe-to-reply gesture on message bubbles
// further down this file. A second touch starts a pinch instead,
// and a quick second tap in roughly the same spot toggles 2x zoom -
// double-tap-to-zoom's usual companion gesture alongside a real pinch.
mediaViewerModal.addEventListener('touchstart', function (event) {
    if (event.touches.length === 2) {
        mediaViewerSwipeStartX = null;
        mediaViewerPanStart = null;
        mediaViewerPinchStartDistance = touchPairDistance(event.touches);
        mediaViewerPinchStartScale = mediaViewerZoomScale;
        return;
    }
    if (event.touches.length !== 1) {
        mediaViewerSwipeStartX = null;
        return;
    }
    mediaViewerPinchStartDistance = null;

    // Checked FIRST, before deciding whether this touch starts a pan
    // or a swipe-setup below - a second quick tap needs to toggle zoom
    // regardless of whatever the CURRENT zoom level already is,
    // including zooming back OUT from an already zoomed-in/panned
    // state. Doing this check after the zoomScale > 1 branch below
    // would mean every touchstart while already zoomed in gets treated
    // as "start panning" and a double-tap to zoom back out would never
    // actually register at all.
    var now = Date.now();
    var isDoubleTap = now - mediaViewerLastTapAt < 300;
    mediaViewerLastTapAt = isDoubleTap ? 0 : now;

    if (isDoubleTap) {
        mediaViewerSwipeStartX = null;
        mediaViewerPanStart = null;
        setMediaViewerZoom(mediaViewerZoomScale > 1 ? 1 : 2);
        return;
    }

    if (mediaViewerZoomScale > 1) {
        mediaViewerSwipeStartX = null;
        mediaViewerPanStart = {
            x: event.touches[0].clientX,
            y: event.touches[0].clientY,
            panX: mediaViewerPanX,
            panY: mediaViewerPanY,
        };
        return;
    }

    mediaViewerPanStart = null;
    mediaViewerSwipeStartX = event.touches[0].clientX;
    mediaViewerSwipeStartY = event.touches[0].clientY;
}, { passive: true });

mediaViewerModal.addEventListener('touchmove', function (event) {
    if (event.touches.length === 2 && mediaViewerPinchStartDistance) {
        var distance = touchPairDistance(event.touches);
        setMediaViewerZoom(mediaViewerPinchStartScale * (distance / mediaViewerPinchStartDistance));
        return;
    }
    if (event.touches.length === 1 && mediaViewerPanStart) {
        mediaViewerPanX = mediaViewerPanStart.panX + (event.touches[0].clientX - mediaViewerPanStart.x);
        mediaViewerPanY = mediaViewerPanStart.panY + (event.touches[0].clientY - mediaViewerPanStart.y);
        clampMediaViewerPan();
        applyMediaViewerZoomTransform();
    }
}, { passive: true });

mediaViewerModal.addEventListener('touchend', function (event) {
    mediaViewerPinchStartDistance = null;
    mediaViewerPanStart = null;

    if (mediaViewerSwipeStartX === null) {
        return;
    }
    var touch = event.changedTouches[0];
    var dx = touch.clientX - mediaViewerSwipeStartX;
    var dy = Math.abs(touch.clientY - mediaViewerSwipeStartY);
    mediaViewerSwipeStartX = null;

    if (Math.abs(dx) > MEDIA_VIEWER_SWIPE_THRESHOLD_PX && dy < MEDIA_VIEWER_SWIPE_THRESHOLD_PX) {
        showAdjacentMediaViewerImage(dx < 0 ? 1 : -1);
    }
}, { passive: true });

function elementFor(mediaType, url) {
    if (mediaType === 'image') {
        var img = document.createElement('img');
        img.src = url;
        img.className = 'max-w-full rounded-lg';
        img.loading = 'lazy';
        img._mediaEl = img;
        return img;
    }

    if (mediaType === 'video') {
        return createVideoPlayer(url);
    }

    if (mediaType === 'audio') {
        return createAudioPlayer(url);
    }

    return null;
}

        // One View always opens in a full-screen modal (WhatsApp-style),
        // never inline in the chat bubble. Images auto-close after ~5s;
        // video autoplays and closes itself once playback actually ends
        // (not on a fixed timer, since duration varies) - both close on
        // backdrop tap / the close button too, as a manual override.
        var oneViewModal = document.getElementById('one-view-modal');
        var oneViewModalContent = document.getElementById('one-view-modal-content');
        var oneViewModalClose = document.getElementById('one-view-modal-close');
        var oneViewModalReply = document.getElementById('one-view-modal-reply');
        var oneViewModalReact = document.getElementById('one-view-modal-react');
        var oneViewCloseTimer = null;
        var oneViewObjectUrl = null;
        var oneViewCurrentMessage = null;
        var oneViewHiddenGuard = document.getElementById('one-view-hidden-guard');

        // Explored per direct request: gate viewing a One View item to
        // the installed PWA only, prompting a plain browser tab to
        // install first. Being completely honest about what this is -
        // there is NO web platform API, on ANY browser or OS, that lets
        // even an installed/standalone PWA detect or block a real
        // screenshot/screen-recording (that requires OS-level support
        // like Android's native FLAG_SECURE, which a web app cannot set
        // for itself no matter how it's installed). This gate adds real
        // FRICTION - matching what was asked - but zero actual technical
        // protection beyond what already exists above (the focus-loss
        // guard). Never present this to the user as "more secure."
        function isRunningAsInstalledPwa() {
            return window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
        }

        async function ensureOneViewAllowed() {
            if (isRunningAsInstalledPwa()) {
                return true;
            }

            var isIos = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;

            if (window.__meetInstallPrompt) {
                var install = await MeetDialog.confirm('One View photos and videos can only be opened in the installed app. Install it now?');
                if (install) {
                    window.__meetInstallPrompt.prompt();
                    window.__meetInstallPrompt = null;
                }
                return false;
            }

            if (isIos) {
                await MeetDialog.alert('One View photos and videos can only be opened in the installed app. Tap Share, then "Add to Home Screen" to install it.');
                return false;
            }

            await MeetDialog.alert('One View photos and videos can only be opened in the installed app. Look for an install option in your browser\'s menu.');
            return false;
        }

        // Best-effort only, and deliberately described as such to the
        // user: a browser tab has no API to detect (let alone block) a
        // screenshot or screen-recording key combo / OS tool, so this
        // cannot make either impossible. What it covers for real is the
        // one case that DOES fire a real event - the window/tab losing
        // focus, which is exactly the moment the OS captures the
        // preview shown in an app-switcher / recents list - by
        // instantly hiding the media behind an opaque guard until focus
        // returns.
        function setOneViewHidden(hidden) {
            if (oneViewModal.classList.contains('hidden')) return; // nothing open to protect
            oneViewHiddenGuard.classList.toggle('hidden', !hidden);
            oneViewHiddenGuard.classList.toggle('flex', hidden);
            var mediaEl = oneViewModalContent.querySelector('video, audio');
            if (mediaEl && hidden && !mediaEl.paused) {
                mediaEl.pause();
            }
        }

        document.addEventListener('visibilitychange', function () {
            setOneViewHidden(document.visibilityState === 'hidden');
        });
        window.addEventListener('blur', function () { setOneViewHidden(true); });
        window.addEventListener('focus', function () { setOneViewHidden(false); });

        function closeOneViewModal(placeholderEl) {
            oneViewHiddenGuard.classList.add('hidden');
            oneViewHiddenGuard.classList.remove('flex');
            clearTimeout(oneViewCloseTimer);
            oneViewCloseTimer = null;
            oneViewModal.classList.add('hidden');
            oneViewModal.classList.remove('flex');
            oneViewModalContent.innerHTML = '';
            oneViewCurrentMessage = null;
            if (oneViewObjectUrl) {
                URL.revokeObjectURL(oneViewObjectUrl);
                oneViewObjectUrl = null;
            }
            if (placeholderEl && placeholderEl.isConnected) {
                loadMessages();
            }
        }

        oneViewModalClose.addEventListener('click', function () { closeOneViewModal(); });
        oneViewModal.addEventListener('click', function (event) {
            if (event.target === oneViewModal) closeOneViewModal();
        });

        // Reply/React work directly from the full-screen viewer, not
        // just the thread's own actions card - a View Once viewer isn't
        // a dead end. Closing the modal first (rather than leaving it
        // open behind the reply banner/emoji picker) matches how
        // closeOneViewModal() already reloads the thread anyway once a
        // receiver's view has consumed it.
        oneViewModalReply.addEventListener('click', function () {
            var message = oneViewCurrentMessage;
            if (!message) return;
            closeOneViewModal();
            startReply(message.id, message.body || '');
        });

        oneViewModalReact.addEventListener('click', function () {
            var message = oneViewCurrentMessage;
            if (!message) return;
            openEmojiPicker(function (emoji) {
                reactToMessage(message.id, emoji);
            });
        });

        function viewOneViewMedia(message, placeholderEl) {
            axios.get(message.media.url, { responseType: 'blob' }).then(function (response) {
                oneViewObjectUrl = URL.createObjectURL(response.data);
                var el = elementFor(message.media.media_type, oneViewObjectUrl);
                if (!el) {
                    return;
                }
                // Images are a bare <img> (el === el._mediaEl) so
                // sizing applies directly; video/audio are wrapper
                // divs around the player controls (see
                // createVideoPlayer()/createAudioPlayer()) - constrain
                // the wrapper's max size instead of overwriting its own
                // layout classes.
                if (message.media.media_type === 'image') {
                    el.className = 'max-h-[85vh] max-w-full rounded-lg object-contain';
                } else {
                    el.style.maxHeight = '85vh';
                    el.style.maxWidth = '100%';
                }

                // Blocks the plain drag-image-to-desktop save path -
                // not a real screenshot/recording defense, just closes
                // off the easiest deliberate save route alongside the
                // oncontextmenu block on the modal itself.
                el.setAttribute('draggable', 'false');
                el.addEventListener('dragstart', function (event) { event.preventDefault(); });

                oneViewModalContent.innerHTML = '';
                oneViewModalContent.appendChild(el);
                oneViewModal.classList.remove('hidden');
                oneViewModal.classList.add('flex');
                oneViewCurrentMessage = message;

                if (message.media.media_type === 'image') {
                    oneViewCloseTimer = setTimeout(function () { closeOneViewModal(placeholderEl); }, 5000);
                } else if (message.media.media_type === 'video') {
                    el._mediaEl.autoplay = true;
                    el._mediaEl.addEventListener('ended', function () { closeOneViewModal(placeholderEl); });
                } else {
                    // Audio has no visual "done" moment as clear as a
                    // video's ended event - close it the same way the
                    // image timer does, just longer.
                    oneViewCloseTimer = setTimeout(function () { closeOneViewModal(placeholderEl); }, 15000);
                }
            }).catch(function (error) {
                console.error('Failed to load one-view media', error);
                showError(error.response && error.response.status === 410
                    ? 'This media has already been viewed.'
                    : 'Could not load media.');
            });
        }

        // Full emoji picker (shared by every quick reaction strip's
        // "more" button and the One View modal's React button above).
        // Recently-used emoji are remembered locally and shown first.
        var EMOJI_CATEGORIES = {
            'Smileys': ['😀','😃','😄','😁','😆','😅','🤣','😂','🙂','🙃','😉','😊','😇','🥰','😍','🤩','😘','😗','😚','😙','😋','😛','😜','🤪','😝','🤑','🤗','🤭','🤫','🤔','🤐','🤨','😐','😑','😶','😏','😒','🙄','😬','🤥','😌','😔','😪','🤤','😴','😷','🤒','🤕','🤢','🤮','🤧','🥵','🥶','🥴','😵','🤯','🤠','🥳','😎','🤓','🧐','😕','😟','🙁','☹️','😮','😯','😲','😳','🥺','😦','😧','😨','😰','😥','😢','😭','😱','😖','😣','😞','😓','😩','😫','🥱','😤','😡','😠','🤬'],
            'Gestures & people': ['👋','🤚','🖐️','✋','🖖','👌','🤏','✌️','🤞','🤟','🤘','🤙','👈','👉','👆','🖕','👇','☝️','👍','👎','✊','👊','🤛','🤜','👏','🙌','👐','🤲','🙏','✍️','💅','🤳','💪','🦵','🦶','👂','👃','🧠','👀','👄'],
            'Animals & nature': ['🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐨','🐯','🦁','🐮','🐷','🐸','🐵','🙈','🙉','🙊','🐔','🐧','🐦','🦆','🦉','🦇','🐺','🐗','🐴','🦄','🐝','🐛','🦋','🐌','🐞','🐢','🐍','🦎','🐙','🦑','🦀','🐡','🐠','🐟','🐬','🐳','🐋','🦈','🐊','🐆','🐘','🐐','🐑','🐎','🐕','🐈','🐓','🦃','🕊️','🐇','🦔'],
            'Food & drink': ['🍏','🍎','🍐','🍊','🍋','🍌','🍉','🍇','🍓','🫐','🍈','🍒','🍑','🥭','🍍','🥥','🥝','🍅','🍆','🥑','🥦','🥬','🥒','🌶️','🌽','🥕','🧄','🧅','🥔','🥐','🍞','🥖','🧀','🥚','🍳','🥞','🥓','🥩','🍗','🍖','🌭','🍔','🍟','🍕','🌮','🌯','🥗','🍿','🥫','🍣','🍤','🍦','🍩','🍪','🎂','🍰','🧁','🍫','🍬','🍭','🍯','☕','🍵','🥤','🍺','🍻','🥂','🍷','🍸','🍹','🍾'],
            'Activities': ['⚽','🏀','🏈','⚾','🎾','🏐','🎱','🏓','🏸','🥅','🏒','🏏','⛳','🎣','🥊','🥋','🎽','🛹','🎿','🏂','🏋️','🤼','🤸','🤺','🏇','🧘','🏄','🏊','🚣','🚵','🚴','🏆','🥇','🥈','🥉','🏅','🎖️','🎗️','🎫','🎪','🤹','🎭','🎨','🎬','🎤','🎧','🎼','🎹','🥁','🎷','🎺','🎸','🎻','🎲','🎯','🎳','🎮','🎰'],
            'Travel & places': ['🚗','🚕','🚙','🚌','🚎','🏎️','🚓','🚑','🚒','🚐','🚚','🚛','🚜','🛵','🏍️','🚲','🛴','🚨','🚉','✈️','🛫','🛬','🚀','🚁','⛵','🚤','🚢','⚓','🚧','🚦','🗺️','🗽','🗼','🏰','🎡','🎢','🎠','⛲','🏖️','🏝️','🏜️','🌋','⛰️','🏔️','🗻','🏕️','⛺','🏠','🏢','🏬','🏥','🏦','🏨','⛪','🕌'],
            'Objects': ['⌚','📱','💻','⌨️','🖥️','🖨️','📷','📸','📹','🎥','📞','☎️','📺','📻','🔋','🔌','💡','🔦','🕯️','💸','💵','💰','💳','💎','🔧','🔨','⚙️','🔩','🔫','💣','🔪','🚪','🛏️','🛋️','🧸','🖼️','🎁','🎈','🎀','🎊','🎉','✉️','📩','📦','📅','📆','📊','📈','📉','📋','📁','📂','📎','📌','📍','✂️','📝','✏️','🔍','🔒','🔓','🔑'],
            'Symbols': ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💗','💖','💘','💝','💯','💢','💤','✅','❌','❗','❓','‼️','⁉️','⚠️','♻️','🔞','📵','🚫','🔴','🟠','🟡','🟢','🔵','🟣','⚫','⚪','🔺','🔻','▶️','⏸️','⏹️','⏺️','⏩','⏪','◀️','🔼','🔽','➡️','⬅️','⬆️','⬇️','🔀','🔁','🎵','🎶','➕','➖','✖️','💲','™️','©️','®️'],
        };
        var emojiPickerModal = document.getElementById('emoji-picker-modal');
        var emojiPickerBody = document.getElementById('emoji-picker-body');
        var emojiPickerClose = document.getElementById('emoji-picker-close');
        var emojiPickerCallback = null;
        var RECENT_EMOJIS_KEY = 'meet_recent_emojis';
        var MAX_RECENT_EMOJIS = 24;

        function getRecentEmojis() {
            try {
                return JSON.parse(localStorage.getItem(RECENT_EMOJIS_KEY) || '[]');
            } catch (e) {
                return [];
            }
        }

        function recordRecentEmoji(emoji) {
            var recents = getRecentEmojis().filter(function (e) { return e !== emoji; });
            recents.unshift(emoji);
            try {
                localStorage.setItem(RECENT_EMOJIS_KEY, JSON.stringify(recents.slice(0, MAX_RECENT_EMOJIS)));
            } catch (e) { /* storage unavailable - not critical */ }
        }

        function renderEmojiPickerGrid() {
            emojiPickerBody.innerHTML = '';

            function renderSection(title, emojis) {
                var heading = document.createElement('p');
                heading.className = 'mb-1.5 mt-3 text-xs font-semibold text-gray-400 first:mt-0';
                heading.textContent = title;
                emojiPickerBody.appendChild(heading);

                var grid = document.createElement('div');
                grid.className = 'grid grid-cols-8 gap-1';
                emojis.forEach(function (emoji) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.textContent = emoji;
                    btn.className = 'flex h-8 w-8 items-center justify-center rounded-lg text-lg hover:bg-gray-100';
                    btn.addEventListener('click', function () {
                        recordRecentEmoji(emoji);
                        closeEmojiPicker();
                        if (emojiPickerCallback) emojiPickerCallback(emoji);
                    });
                    grid.appendChild(btn);
                });
                emojiPickerBody.appendChild(grid);
            }

            var recents = getRecentEmojis();
            if (recents.length > 0) {
                renderSection('Recently used', recents);
            }
            Object.keys(EMOJI_CATEGORIES).forEach(function (category) {
                renderSection(category, EMOJI_CATEGORIES[category]);
            });
        }

        function openEmojiPicker(callback) {
            emojiPickerCallback = callback;
            renderEmojiPickerGrid();
            emojiPickerModal.classList.remove('hidden');
            emojiPickerModal.classList.add('flex');
        }

        function closeEmojiPicker() {
            emojiPickerModal.classList.add('hidden');
            emojiPickerModal.classList.remove('flex');
            emojiPickerCallback = null;
        }

        emojiPickerClose.addEventListener('click', closeEmojiPicker);
        emojiPickerModal.addEventListener('click', function (event) {
            if (event.target === emojiPickerModal) closeEmojiPicker();
        });

        var messageInfoModal = document.getElementById('message-info-modal');
        var messageInfoModalBody = document.getElementById('message-info-modal-body');
        var messageInfoModalClose = document.getElementById('message-info-modal-close');

        function closeMessageInfoModal() {
            messageInfoModal.classList.add('hidden');
            messageInfoModal.classList.remove('flex');
        }

        messageInfoModalClose.addEventListener('click', closeMessageInfoModal);
        messageInfoModal.addEventListener('click', function (event) {
            if (event.target === messageInfoModal) closeMessageInfoModal();
        });

        function statusLabel(status, deliveredAt, readAt) {
            if (status === 'read' && readAt) {
                return 'Read at ' + formatMessageTime(readAt);
            }
            if (status === 'delivered' && deliveredAt) {
                return 'Delivered at ' + formatMessageTime(deliveredAt);
            }
            return 'Sent';
        }

        function openMessageInfo(messageId) {
            messageInfoModalBody.innerHTML = '<p class="text-gray-400">Loading…</p>';
            messageInfoModal.classList.remove('hidden');
            messageInfoModal.classList.add('flex');

            axios.get('/api/v1/messages/' + messageId + '/info').then(function (response) {
                var recipients = response.data.data;
                messageInfoModalBody.innerHTML = '';

                if (recipients.length === 0) {
                    messageInfoModalBody.innerHTML = '<p class="text-gray-400">No recipients.</p>';
                    return;
                }

                recipients.forEach(function (r) {
                    var row = document.createElement('div');
                    row.className = 'flex items-center justify-between gap-3';

                    var who = document.createElement('div');
                    who.className = 'flex items-center gap-2';
                    var avatar = document.createElement('span');
                    avatar.className = 'flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700';
                    avatar.textContent = (r.user.full_name || '?').trim().charAt(0).toUpperCase();
                    var name = document.createElement('span');
                    name.className = 'font-medium text-gray-800';
                    name.textContent = r.user.full_name;
                    who.appendChild(avatar);
                    who.appendChild(name);

                    var status = document.createElement('span');
                    status.className = r.status === 'read' ? 'text-amber-600' : 'text-gray-400';
                    status.textContent = statusLabel(r.status, r.delivered_at, r.read_at);

                    row.appendChild(who);
                    row.appendChild(status);
                    messageInfoModalBody.appendChild(row);
                });
            }).catch(function (error) {
                console.error('Failed to load message info', error);
                messageInfoModalBody.innerHTML = '<p class="text-red-500">Could not load message info.</p>';
            });
        }

        function buildOneViewOpenedElement() {
            var opened = document.createElement('div');
            opened.className = 'message-one-view-content flex items-center gap-1.5 rounded-lg bg-black/5 px-3 py-2 text-xs font-medium text-gray-500';
            opened.innerHTML =
                '<svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                '<circle cx="12" cy="12" r="9" /><path d="m9 12 2 2 4-4" /></svg><span>Opened</span>';
            return opened;
        }

        function renderMediaContent(message, fromMe) {
            if (message.message_type === 'one_view') {
                // Once consumed, the file is genuinely deleted server-side
                // (not just marked) - this now applies to sender and
                // receiver alike, so both see "Opened" here instead of the
                // sender perpetually seeing "Tap to view once" for a file
                // that's already gone (a real gap: the backend already
                // 410s the sender's own re-open attempt once consumed, but
                // this UI never reflected that until now).
                if (message.one_view_consumed) {
                    return buildOneViewOpenedElement();
                }

                if (fromMe) {
                    // The sender never gets a clickable preview here, not
                    // even before the receiver has opened it - matches
                    // real WhatsApp (and the backend, which now 403s a
                    // sender's own view attempt outright rather than
                    // letting them re-preview it - see OneViewService::view()).
                    var senderLabel = document.createElement('div');
                    senderLabel.className = 'message-one-view-content flex items-center gap-2 rounded-lg bg-black/10 px-3 py-2.5 text-xs font-medium opacity-90';
                    senderLabel.innerHTML =
                        '<span class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-gray-800/80 text-white">' +
                        '<svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' +
                        '<rect x="3" y="6" width="18" height="14" rx="2" /><circle cx="12" cy="13" r="3.2" /></svg>' +
                        '<span class="absolute -right-1.5 -top-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-brand-600 text-[10px] font-bold text-white">1</span>' +
                        '</span><span>One View</span>';
                    return senderLabel;
                }

                var placeholder = document.createElement('button');
                placeholder.type = 'button';
                placeholder.className = 'message-one-view-content flex items-center gap-2 rounded-lg bg-black/10 px-3 py-2.5 text-xs font-medium hover:bg-black/15';
                placeholder.innerHTML =
                    '<span class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-gray-800/80 text-white">' +
                    '<svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' +
                    '<rect x="3" y="6" width="18" height="14" rx="2" /><circle cx="12" cy="13" r="3.2" /></svg>' +
                    '<span class="absolute -right-1.5 -top-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-brand-600 text-[10px] font-bold text-white">1</span>' +
                    '</span><span>Tap to view once</span>';
                placeholder.addEventListener('click', async function () {
                    if (!(await ensureOneViewAllowed())) {
                        return;
                    }
                    viewOneViewMedia(message, placeholder);
                });

                return placeholder;
            }

            if (!message.media) {
                return null;
            }

            var el = elementFor(message.media.media_type, message.media.url);
            if (el) {
                // Only images get tap-to-expand here - video/audio
                // already carry their own full custom controls right
                // in the bubble (see createVideoPlayer()/
                // createAudioPlayer()), so there's no separate
                // "expanded" step needed for those.
                if (message.media.media_type === 'image') {
                    el.classList.add('cursor-pointer');
                    el.addEventListener('click', function () { openImageViewer(message.media.url, el); });
                }
                return el;
            }

            var link = document.createElement('a');
            link.href = message.media.url;
            link.target = '_blank';
            link.rel = 'noopener';
            link.className = 'flex items-center gap-1 underline';
            link.textContent = '📄 ' + message.media.original_filename;
            return link;
        }

        function renderSystemMessage(message) {
            // Centered gray pill, same style as the privacy banner above
            // the message list - used for call-log rows (see
            // CallService::logCallInConversation()), not a chat bubble
            // since it's not authored by either participant.
            var outer = document.createElement('div');
            outer.className = 'flex justify-center';
            outer.dataset.messageId = message.id;

            var pill = document.createElement('div');
            pill.className = 'mx-auto max-w-xs rounded-lg bg-gray-50 px-3 py-2 text-center text-[11px] text-gray-400';
            pill.textContent = message.body || '';
            outer.appendChild(pill);

            return outer;
        }

        function renderMessage(message) {
            if (message.message_type === 'system') {
                return renderSystemMessage(message);
            }

            var fromMe = message.from_me;

            var outer = document.createElement('div');
            outer.className = 'group flex flex-col ' + (fromMe ? 'items-end' : 'items-start');
            outer.dataset.messageId = message.id;
            outer.dataset.senderId = message.sender_id;
            // Always the plaintext by this point - decryptMessageInPlace()
            // runs before renderMessage() for any e2e message, so this
            // dataset never holds ciphertext (used as-is by Reply/Forward).
            outer.dataset.body = message.body || '';
            outer.dataset.encryptionMode = message.encryption_mode || 'none';
            outer.dataset.keyVersion = message.key_version || '';

            var bubble = document.createElement('div');
            bubble.className = (fromMe
                ? 'bg-brand-600 text-white'
                : 'bg-gray-100 text-gray-900') + ' message-bubble max-w-xs cursor-pointer touch-pan-y select-none rounded-2xl px-4 py-2 text-sm shadow-sm transition-transform duration-150 sm:max-w-sm sm:cursor-auto sm:select-text';

            if (message.reply_to) {
                var replyPreview = document.createElement('div');
                replyPreview.className = (fromMe ? 'border-white/30 text-white/80' : 'border-gray-300 text-gray-500')
                    + ' mb-1 cursor-pointer border-l-2 pl-2 text-xs hover:opacity-80';
                replyPreview.textContent = message.reply_to.preview || 'Attachment';
                replyPreview.dataset.jumpToMessageId = message.reply_to.message_id;
                bubble.appendChild(replyPreview);
            }

            var mediaEl = renderMediaContent(message, fromMe);
            if (mediaEl) {
                bubble.appendChild(mediaEl);
            }

            if (message.body && message.message_type !== 'one_view') {
                var bodyEl = document.createElement('div');
                bodyEl.className = (mediaEl ? 'mt-1 ' : '') + 'break-words';
                bodyEl.innerHTML = linkifyHtml(message.body) + (message.edited_at ? ' <span class="text-xs opacity-70">(edited)</span>' : '');
                bubble.appendChild(bodyEl);

                if (message.message_type === 'text') {
                    var firstLinkUrl = firstUrl(message.body);
                    if (firstLinkUrl) {
                        fetchLinkPreview(firstLinkUrl, bubble);
                    }
                }
            }

            if (fromMe && typeof message.id === 'number') {
                var statusRow = document.createElement('div');
                statusRow.className = 'message-status-row mt-1 flex items-center justify-end gap-1 text-[10px] text-white/70';
                var timeEl = document.createElement('span');
                timeEl.textContent = formatMessageTime(message.created_at);
                statusRow.appendChild(timeEl);
                var tick = document.createElement('span');
                tick.className = 'message-status-tick inline-flex';
                renderTick(tick, message.delivery_status);
                statusRow.appendChild(tick);
                bubble.appendChild(statusRow);
            }

            // Checkbox lives in a row alongside the bubble (not inside
            // outer's plain flex-col stack) so it sits at the bubble's
            // outer edge instead of stacking above/below it. Hidden
            // outside message-select mode (see the IIFE below).
            var row = document.createElement('div');
            row.className = 'flex w-full items-center gap-2 ' + (fromMe ? 'justify-end' : '');
            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'message-select-checkbox h-4 w-4 shrink-0 rounded border-gray-300 text-brand-600 focus:ring-brand-500 ' + (messageSelectModeActive ? '' : 'hidden');
            row.appendChild(checkbox);
            row.appendChild(bubble);
            outer.appendChild(row);

            // Always present (even with nothing in it yet) so a later
            // reaction change - the sender's own optimistic update, or
            // syncReactions() picking up the other side's - has a fixed
            // element to patch via applyReactionsToContainer() instead
            // of needing to insert/remove this row from the DOM.
            var reactionsRow = document.createElement('div');
            reactionsRow.className = 'message-reactions-row mt-1 flex gap-1 hidden';
            outer.appendChild(reactionsRow);

            if (typeof message.id === 'number') {
                messageReactionsMap[message.id] = message.reactions || [];
            }
            renderReactionBadges(reactionsRow, message.reactions || []);

            // A single floating card (WhatsApp-style long-press menu),
            // not an inline row pushed into the document flow below the
            // bubble - position:fixed, positioned near the bubble only
            // once actually opened (see positionAndShowMessageMenu()),
            // never visible by default. Tapping the bubble toggles it
            // (see the messagesEl click handler below); swiping the
            // bubble right is a direct shortcut straight to Reply
            // without needing this menu at all (see the touch handlers
            // attached to `bubble` below).
            var actions = document.createElement('div');
            actions.className = 'message-actions-menu fixed z-30 hidden max-h-[70vh] w-52 flex-col overflow-y-auto rounded-xl border border-gray-100 bg-white py-1.5 text-sm shadow-xl';

            // Tapping the emoji you've already reacted with removes it
            // (un-react) instead of just re-adding the same reaction -
            // the server already treats one reaction per user per
            // message as a slot (switching emoji replaces it), so the
            // only genuinely missing case was reacting with the exact
            // same emoji again.
            var myReaction = message.reactions && message.reactions.find(function (r) { return r.user_id === currentUserId; });
            var reactionStrip = document.createElement('div');
            reactionStrip.className = 'flex items-center justify-around border-b border-gray-100 px-2 py-2';
            REACTION_EMOJIS.forEach(function (emoji) {
                var isActive = !!myReaction && myReaction.emoji === emoji;
                var emojiBtn = document.createElement('button');
                emojiBtn.type = 'button';
                emojiBtn.textContent = emoji;
                emojiBtn.className = 'rounded-full text-lg leading-none transition hover:scale-125' + (isActive ? ' scale-125 bg-brand-50 ring-2 ring-brand-300' : '');
                emojiBtn.title = isActive ? 'Remove reaction' : 'React';
                emojiBtn.dataset.action = 'react';
                emojiBtn.dataset.emoji = emoji;
                emojiBtn.dataset.active = isActive ? 'true' : 'false';
                reactionStrip.appendChild(emojiBtn);
            });
            var moreReactionBtn = document.createElement('button');
            moreReactionBtn.type = 'button';
            moreReactionBtn.className = 'flex h-7 w-7 items-center justify-center rounded-full text-base text-gray-400 hover:bg-gray-100';
            moreReactionBtn.textContent = '+';
            moreReactionBtn.title = 'More emojis';
            moreReactionBtn.addEventListener('click', function (event) {
                var reactionContainer = findMessageContainer(event.target);
                closeAllMessageMenus();
                openEmojiPicker(function (emoji) {
                    reactToMessage(reactionContainer.dataset.messageId, emoji);
                });
            });
            reactionStrip.appendChild(moreReactionBtn);
            actions.appendChild(reactionStrip);

            function actionButton(icon, label, action) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'flex w-full items-center gap-3 px-4 py-2.5 text-left text-gray-700 hover:bg-gray-50';
                btn.dataset.action = action;
                btn.innerHTML = '<span class="w-5 text-center">' + icon + '</span><span>' + label + '</span>';
                return btn;
            }

            actions.appendChild(actionButton('&#8617;&#65039;', 'Reply', 'reply'));
            actions.appendChild(actionButton('&#10132;', 'Forward', 'forward'));
            if (message.body) {
                actions.appendChild(actionButton('&#128203;', 'Copy', 'copy'));
            }
            actions.appendChild(actionButton('&#11088;', message.is_starred ? 'Unstar' : 'Star', 'star'));
            actions.appendChild(actionButton('&#128204;', message.is_pinned ? 'Unpin' : 'Pin', 'pin'));
            actions.appendChild(actionButton('&#128465;&#65039;', 'Delete for me', 'delete-me'));
            if (fromMe) {
                actions.appendChild(actionButton('&#128465;&#65039;', 'Delete for everyone', 'delete-everyone'));
                if (typeof message.id === 'number') {
                    actions.appendChild(actionButton('&#8505;&#65039;', 'Info', 'info'));
                }
            }

            outer.appendChild(actions);

            return outer;
        }

        function findMessageContainer(el) {
            return el.closest('[data-message-id]');
        }

        function renderReactionBadges(rowEl, reactions) {
            rowEl.innerHTML = '';
            if (!reactions || reactions.length === 0) {
                rowEl.classList.add('hidden');
                return;
            }
            var counts = {};
            reactions.forEach(function (r) { counts[r.emoji] = (counts[r.emoji] || 0) + 1; });
            Object.keys(counts).forEach(function (emoji) {
                var badge = document.createElement('span');
                badge.className = 'rounded-full bg-white border border-gray-200 px-1.5 py-0.5 text-xs shadow-sm';
                badge.textContent = emoji + ' ' + counts[emoji];
                rowEl.appendChild(badge);
            });
            rowEl.classList.remove('hidden');
        }

        // Single choke point for "this message's reactions just
        // changed" - reactToMessage()/removeReactionFromMessage()'s own
        // optimistic update and syncReactions() picking up the other
        // side's change both funnel through here, so the visible
        // badges and the reaction strip's own highlighted-emoji state
        // never drift apart from messageReactionsMap.
        function applyReactionsToContainer(messageId, reactions) {
            var container = messagesEl.querySelector('[data-message-id="' + messageId + '"]');
            if (!container) {
                return;
            }
            var row = container.querySelector('.message-reactions-row');
            if (row) {
                renderReactionBadges(row, reactions);
            }
            var myReaction = reactions.find(function (r) { return r.user_id === currentUserId; });
            container.querySelectorAll('.message-actions-menu button[data-action="react"]').forEach(function (btn) {
                var isActive = !!myReaction && myReaction.emoji === btn.dataset.emoji;
                btn.className = 'rounded-full text-lg leading-none transition hover:scale-125' + (isActive ? ' scale-125 bg-brand-50 ring-2 ring-brand-300' : '');
                btn.title = isActive ? 'Remove reaction' : 'React';
                btn.dataset.active = isActive ? 'true' : 'false';
            });
        }

        // Reacting used to wait for the reaction POST to land, then
        // reload the ENTIRE thread from scratch just to show your own
        // tap took effect - both a full extra round trip and a heavy
        // teardown/rebuild of every message on screen. This now updates
        // the badge/highlight locally the instant you tap, reverting
        // only if the request actually fails.
        function reactToMessage(messageId, emoji) {
            var previous = messageReactionsMap[messageId] || [];
            var next = previous.filter(function (r) { return r.user_id !== currentUserId; });
            next.push({ user_id: currentUserId, emoji: emoji });
            messageReactionsMap[messageId] = next;
            applyReactionsToContainer(messageId, next);

            axios.post('/api/v1/messages/' + messageId + '/reaction', { emoji: emoji })
                .then(function () { clearError(); })
                .catch(function (error) {
                    console.error('Failed to react', error);
                    messageReactionsMap[messageId] = previous;
                    applyReactionsToContainer(messageId, previous);
                    showError('Could not add reaction.');
                });
        }

        function removeReactionFromMessage(messageId) {
            var previous = messageReactionsMap[messageId] || [];
            var next = previous.filter(function (r) { return r.user_id !== currentUserId; });
            messageReactionsMap[messageId] = next;
            applyReactionsToContainer(messageId, next);

            axios.delete('/api/v1/messages/' + messageId + '/reaction')
                .then(function () { clearError(); })
                .catch(function (error) {
                    console.error('Failed to remove reaction', error);
                    messageReactionsMap[messageId] = previous;
                    applyReactionsToContainer(messageId, previous);
                    showError('Could not remove reaction.');
                });
        }

        // Companion to updateMyMessageStatuses() below, same
        // piggyback-on-the-existing-poll idea - but for reactions on
        // ANY already-rendered message, not just the polling user's
        // own. pollNewMessages()'s after=lastMessageId fetch can never
        // re-return a message once it's been rendered, so a reaction
        // the other side adds (or removes) on an existing message would
        // otherwise stay invisible until a full reload - see
        // MessageRepository::reactionsInRange()'s docblock server-side.
        function syncReactions() {
            var fromId = oldestLoadedMessageId !== null ? oldestLoadedMessageId : lastMessageId;
            if (!fromId || !lastMessageId || fromId > lastMessageId) {
                return;
            }
            axios.get('/api/v1/conversations/' + conversationId + '/message-reactions', { params: { from: fromId, to: lastMessageId } })
                .then(function (response) {
                    var updates = response.data.data;
                    Object.keys(messageReactionsMap).forEach(function (idStr) {
                        var id = Number(idStr);
                        if (id < fromId || id > lastMessageId) {
                            return;
                        }
                        var reactions = updates[idStr] || [];
                        messageReactionsMap[id] = reactions;
                        applyReactionsToContainer(id, reactions);
                    });
                })
                .catch(function (error) {
                    console.error('Failed to sync reactions', error);
                });
        }

        function startReply(messageId, body) {
            replyingToId = messageId;
            replyingToBody = body || null;
            replyBannerText.textContent = 'Replying to: ' + (body || 'message');
            replyBanner.classList.remove('hidden');
            replyBanner.classList.add('flex');
            composerInput.focus();
        }

        function cancelReply() {
            replyingToId = null;
            replyingToBody = null;
            replyBanner.classList.add('hidden');
            replyBanner.classList.remove('flex');
        }

        replyBannerCancel.addEventListener('click', cancelReply);

        // The compose-time image preview (openComposeImagePreview() below)
        // navigates through exactly these elements, in this order -
        // rebuilt every render alongside the thumbnails themselves.
        var composePreviewImages = [];

        function renderAttachmentPreviews() {
            // Revoke any thumbnail object URLs from the previous render
            // before rebuilding, so selecting/removing files repeatedly
            // doesn't leak blob URLs.
            attachmentPreviewThumbs.querySelectorAll('[data-object-url]').forEach(function (el) {
                URL.revokeObjectURL(el.dataset.objectUrl);
            });
            attachmentPreviewThumbs.innerHTML = '';
            composePreviewImages = [];

            pendingAttachments.forEach(function (item, index) {
                var chip = document.createElement('div');
                chip.className = 'relative';

                if (!item.isVoiceNote && item.file.type.startsWith('image/')) {
                    var url = URL.createObjectURL(item.file);
                    var img = document.createElement('img');
                    img.src = url;
                    img.dataset.objectUrl = url;
                    img.className = 'h-14 w-14 cursor-pointer rounded-lg object-cover';
                    // Tapping a selected image before sending previews it
                    // full-size with Next/Previous through the OTHER
                    // selected images - the same carousel already built
                    // for images already in the thread, just fed this
                    // batch's own object URLs instead.
                    img.addEventListener('click', function () {
                        openComposeImagePreview(this);
                    });
                    composePreviewImages.push(img);
                    chip.appendChild(img);
                } else if (!item.isVoiceNote && item.file.type.startsWith('video/')) {
                    // A real preview of the actual video (its first
                    // frame), not just a filename/extension box - the
                    // <video> element shows that on its own once loaded,
                    // no extra thumbnail-generation step needed.
                    var videoUrl = URL.createObjectURL(item.file);
                    var video = document.createElement('video');
                    video.src = videoUrl;
                    video.dataset.objectUrl = videoUrl;
                    video.muted = true;
                    video.playsInline = true;
                    video.className = 'h-14 w-14 rounded-lg object-cover';
                    chip.appendChild(video);
                    var playBadge = document.createElement('span');
                    playBadge.className = 'pointer-events-none absolute inset-0 flex items-center justify-center text-lg text-white drop-shadow';
                    playBadge.textContent = '▶';
                    chip.appendChild(playBadge);
                } else {
                    var box = document.createElement('div');
                    box.className = 'flex h-14 w-14 items-center justify-center rounded-lg bg-gray-200 px-1 text-center text-[10px] text-gray-600';
                    box.textContent = item.isVoiceNote ? 'Voice' : (item.file.name.split('.').pop() || 'file').toUpperCase();
                    chip.appendChild(box);
                }

                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'absolute -right-1.5 -top-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-gray-700 text-[10px] text-white hover:bg-gray-900';
                removeBtn.textContent = '×';
                removeBtn.addEventListener('click', function () {
                    pendingAttachments.splice(index, 1);
                    if (pendingAttachments.length === 0) {
                        clearPendingAttachment();
                    } else {
                        renderAttachmentPreviews();
                        updateAttachmentPreviewText();
                    }
                });
                chip.appendChild(removeBtn);

                attachmentPreviewThumbs.appendChild(chip);
            });
        }

        // "Make PDF" shows when at least one picked image (not a voice note / video / document) is pending
        var makePdfBtn = document.getElementById('attachment-make-pdf');
        function pendingImages() {
            return pendingAttachments.filter(function (item) {
                return !item.isVoiceNote && item.file && /^image\//.test(item.file.type || '');
            });
        }
        function refreshMakePdfButton() {
            if (!makePdfBtn) return;
            makePdfBtn.classList.toggle('hidden', pendingImages().length === 0 || typeof window.MeetImagesToPdf !== 'function');
        }
        if (makePdfBtn) {
            makePdfBtn.addEventListener('click', function () {
                var images = pendingImages();
                if (!images.length) return;
                makePdfBtn.disabled = true;
                makePdfBtn.textContent = 'Making PDF...';
                var stamp = new Date().toISOString().slice(0, 16).replace(/[-:T]/g, '');
                window.MeetImagesToPdf(images.map(function (i) { return i.file; }), 'images-' + stamp + '.pdf').then(function (pdf) {
                    var first = pendingAttachments.indexOf(images[0]);
                    pendingAttachments = pendingAttachments.filter(function (item) { return images.indexOf(item) === -1; });
                    pendingAttachments.splice(Math.max(0, Math.min(first, pendingAttachments.length)), 0, { file: pdf, isVoiceNote: false, durationSeconds: null });
                    renderAttachmentPreviews();
                    updateAttachmentPreviewText();
                }).catch(function (err) {
                    alert('Could not make the PDF: ' + (err && err.message ? err.message : err));
                }).finally(function () {
                    makePdfBtn.disabled = false;
                    makePdfBtn.textContent = 'Make PDF';
                });
            });
        }

        function updateAttachmentPreviewText() {
            refreshMakePdfButton();
            if (pendingAttachments.length === 1 && pendingAttachments[0].isVoiceNote) {
                attachmentPreviewText.textContent = 'Voice note (' + Math.round(pendingAttachments[0].durationSeconds || 0) + 's)';
            } else if (pendingAttachments.length === 1) {
                attachmentPreviewText.textContent = pendingAttachments[0].file.name;
            } else {
                attachmentPreviewText.textContent = pendingAttachments.length + ' files selected';
            }
        }

        // A gallery-picked phone photo went straight into the upload
        // request untouched at full camera resolution (often several
        // MB) - real, uncompensated transfer time on a typical mobile
        // upload link, which is most of what "photo upload is ~2s
        // late" actually was. Skipped for anything already reasonably
        // small (not worth the re-encode) and for GIFs (canvas would
        // flatten the animation to its first frame).
        var IMAGE_COMPRESS_MAX_DIMENSION_PX = 1600;
        var IMAGE_COMPRESS_QUALITY = 0.8;
        var IMAGE_COMPRESS_SKIP_UNDER_BYTES = 400 * 1024;

        function compressImageIfNeeded(file) {
            if (!file.type || file.type.indexOf('image/') !== 0 || file.type === 'image/gif'
                || file.size <= IMAGE_COMPRESS_SKIP_UNDER_BYTES || typeof createImageBitmap !== 'function') {
                return Promise.resolve(file);
            }
            return createImageBitmap(file).then(function (bitmap) {
                var scale = Math.min(1, IMAGE_COMPRESS_MAX_DIMENSION_PX / Math.max(bitmap.width, bitmap.height));
                var canvas = document.createElement('canvas');
                canvas.width = Math.round(bitmap.width * scale);
                canvas.height = Math.round(bitmap.height * scale);
                canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                if (bitmap.close) bitmap.close();
                return new Promise(function (resolve) {
                    canvas.toBlob(function (blob) {
                        // A handful of already-efficient images can come
                        // back larger after a JPEG re-encode - only use
                        // the result if it's actually a genuine win.
                        if (!blob || blob.size >= file.size) {
                            resolve(file);
                            return;
                        }
                        resolve(new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' }));
                    }, 'image/jpeg', IMAGE_COMPRESS_QUALITY);
                });
            }).catch(function () {
                // Any decode/canvas failure (unsupported format, etc.) -
                // never let a compression nicety block sending.
                return file;
            });
        }

        function addPendingAttachments(files, isVoiceNote, durationSeconds) {
            files.forEach(function (file) {
                var item = { file: file, isVoiceNote: !!isVoiceNote, durationSeconds: durationSeconds || null };
                pendingAttachments.push(item);
                // Swaps item.file in place once ready - the later upload
                // step (see the send handler) just reads item.file at
                // send time, so this needs no other wiring. The preview
                // thumbnail already captured its own object URL from
                // the original file by the time this resolves, so it's
                // unaffected either way.
                if (!isVoiceNote) {
                    compressImageIfNeeded(file).then(function (compressed) {
                        item.file = compressed;
                    });
                }
            });
            renderAttachmentPreviews();
            updateAttachmentPreviewText();
            attachmentPreview.classList.remove('hidden');
            attachmentPreview.classList.add('flex');
            // Send only re-enables on the composer's own `input` event
            // otherwise - a file/voice-note alone (no caption typed) is
            // just as sendable, so it needs the same re-check here.
            sendBtn.disabled = false;
        }

        function clearPendingAttachment() {
            pendingAttachments = [];
            attachmentInputGallery.value = '';
            attachmentInputDocument.value = '';
            oneViewToggle.checked = false;
            attachmentPreviewThumbs.querySelectorAll('[data-object-url]').forEach(function (el) {
                URL.revokeObjectURL(el.dataset.objectUrl);
            });
            attachmentPreviewThumbs.innerHTML = '';
            attachmentPreview.classList.add('hidden');
            attachmentPreview.classList.remove('flex');
        }

        function closeAttachmentMenu() {
            attachmentMenu.classList.add('hidden');
        }

        attachmentButton.addEventListener('click', function (event) {
            event.stopPropagation();
            attachmentMenu.classList.toggle('hidden');
        });

        // Closing on any outside click/tap - the same convention the
        // existing kebab dropdown (#chat-menu-dropdown) and reaction
        // picker already use elsewhere in this file.
        document.addEventListener('click', function (event) {
            if (!attachmentMenu.classList.contains('hidden') && !event.target.closest('#attachment-menu') && event.target !== attachmentButton) {
                closeAttachmentMenu();
            }
        });

        attachmentMenuGallery.addEventListener('click', function () {
            closeAttachmentMenu();
            attachmentInputGallery.click();
        });

        attachmentMenuDocument.addEventListener('click', function () {
            closeAttachmentMenu();
            attachmentInputDocument.click();
        });

        attachmentMenuCamera.addEventListener('click', function () {
            closeAttachmentMenu();
            openCameraModal();
        });

        attachmentInputGallery.addEventListener('change', function () {
            if (attachmentInputGallery.files.length > 0) {
                addPendingAttachments(Array.from(attachmentInputGallery.files), false, null);
            }
        });

        attachmentInputDocument.addEventListener('change', function () {
            if (attachmentInputDocument.files.length > 0) {
                addPendingAttachments(Array.from(attachmentInputDocument.files), false, null);
            }
        });

        attachmentCancel.addEventListener('click', clearPendingAttachment);

        // WhatsApp-style recording bar: starting a recording replaces the
        // normal composer row outright (trash to discard, Pause/Resume,
        // a live reactive waveform sampled from the real mic stream via
        // Web Audio's AnalyserNode - not a fake CSS animation - and a
        // green send arrow that stops and sends immediately, with no
        // separate preview step for a voice note).
        var voiceStream = null;
        var voiceAudioContext = null;
        var voiceAnalyser = null;
        var voiceWaveformLevels = [];
        var VOICE_WAVEFORM_BAR_COUNT = 32;
        var voiceWaveformAnimationFrame = null;
        var voiceRecordingStartedAt = null;
        var voicePausedElapsedMs = 0;
        var voiceRecordingTimerInterval = null;
        var voiceIsPaused = false;
        var voiceSendImmediately = false;

        function renderVoiceWaveformBars() {
            voiceWaveform.innerHTML = '';
            voiceWaveformLevels = [];
            for (var i = 0; i < VOICE_WAVEFORM_BAR_COUNT; i++) {
                voiceWaveformLevels.push(3);
                var bar = document.createElement('span');
                bar.className = 'w-[3px] shrink-0 rounded-full bg-brand-400';
                bar.style.height = '3px';
                voiceWaveform.appendChild(bar);
            }
        }

        function updateVoiceWaveform() {
            if (!voiceAnalyser) return;
            var data = new Uint8Array(voiceAnalyser.frequencyBinCount);
            voiceAnalyser.getByteFrequencyData(data);
            var sum = 0;
            for (var i = 0; i < data.length; i++) sum += data[i];
            var level = Math.min(24, Math.max(3, Math.round(sum / data.length / 4)));

            voiceWaveformLevels.shift();
            voiceWaveformLevels.push(level);
            var bars = voiceWaveform.children;
            for (var j = 0; j < bars.length; j++) {
                bars[j].style.height = voiceWaveformLevels[j] + 'px';
            }

            voiceWaveformAnimationFrame = requestAnimationFrame(updateVoiceWaveform);
        }

        function stopVoiceWaveformLoop() {
            if (voiceWaveformAnimationFrame) {
                cancelAnimationFrame(voiceWaveformAnimationFrame);
                voiceWaveformAnimationFrame = null;
            }
        }

        function formatRecordingTime(ms) {
            var totalSeconds = Math.max(0, Math.floor(ms / 1000));
            var minutes = Math.floor(totalSeconds / 60);
            var seconds = totalSeconds % 60;
            return minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
        }

        function currentRecordingElapsedMs() {
            return voicePausedElapsedMs + (voiceIsPaused ? 0 : Date.now() - voiceRecordingStartedAt);
        }

        function startRecordingTimer() {
            voiceRecordingTimerInterval = setInterval(function () {
                voiceRecordingTimeEl.textContent = formatRecordingTime(currentRecordingElapsedMs());
            }, 250);
        }

        function showVoiceRecordingBar() {
            composerNormalRow.classList.add('hidden');
            composerNormalRow.classList.remove('flex');
            voiceRecordingBar.classList.remove('hidden');
            voiceRecordingBar.classList.add('flex');
        }

        function hideVoiceRecordingBar() {
            voiceRecordingBar.classList.add('hidden');
            voiceRecordingBar.classList.remove('flex');
            composerNormalRow.classList.remove('hidden');
            composerNormalRow.classList.add('flex');
            voiceRecordingDot.classList.remove('opacity-30');
            voiceRecordingPause.textContent = 'Pause';
            voiceRecordingTimeEl.textContent = '0:00';
            resetVoiceSlidePill();
        }

        function cleanupVoiceRecordingState() {
            clearInterval(voiceRecordingTimerInterval);
            voiceRecordingTimerInterval = null;
            stopVoiceWaveformLoop();
            if (voiceStream) {
                voiceStream.getTracks().forEach(function (track) { track.stop(); });
                voiceStream = null;
            }
            if (voiceAudioContext) {
                voiceAudioContext.close().catch(function () {});
                voiceAudioContext = null;
            }
            voiceAnalyser = null;
            voiceIsPaused = false;
            voicePausedElapsedMs = 0;
            voiceRecordingStartedAt = null;
        }

        voiceNoteButton.addEventListener('click', function () {
            navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
                voiceStream = stream;
                recordedChunks = [];
                voiceSendImmediately = false;
                voiceRecordingStartedAt = Date.now();
                voicePausedElapsedMs = 0;
                voiceIsPaused = false;

                mediaRecorder = new MediaRecorder(stream);
                mediaRecorder.ondataavailable = function (event) {
                    if (event.data.size > 0) {
                        recordedChunks.push(event.data);
                    }
                };
                mediaRecorder.onstop = function () {
                    var durationSeconds = Math.round(currentRecordingElapsedMs() / 1000);
                    cleanupVoiceRecordingState();
                    hideVoiceRecordingBar();

                    var blob = new Blob(recordedChunks, { type: 'audio/webm' });
                    var file = new File([blob], 'voice-note.webm', { type: 'audio/webm' });
                    // A voice note replaces any other pending selection
                    // rather than joining it - recording one while files
                    // are already queued isn't a real combined-send case.
                    pendingAttachments = [];
                    addPendingAttachments([file], true, durationSeconds);

                    if (voiceSendImmediately) {
                        composerForm.requestSubmit();
                    }
                };
                mediaRecorder.start();

                renderVoiceWaveformBars();
                voiceAudioContext = new (window.AudioContext || window.webkitAudioContext)();
                voiceAnalyser = voiceAudioContext.createAnalyser();
                voiceAnalyser.fftSize = 64;
                voiceAudioContext.createMediaStreamSource(stream).connect(voiceAnalyser);
                updateVoiceWaveform();
                startRecordingTimer();
                showVoiceRecordingBar();
            }).catch(function (error) {
                console.error('Microphone permission denied or unavailable', error);
                showError('Could not access the microphone. Check your browser permissions.');
            });
        });

        function discardVoiceRecording() {
            if (mediaRecorder) {
                // Detach onstop first so discarding never creates (or
                // sends) an attachment - it only exists to build one.
                mediaRecorder.onstop = null;
                if (mediaRecorder.state !== 'inactive') {
                    mediaRecorder.stop();
                }
            }
            recordedChunks = [];
            cleanupVoiceRecordingState();
            hideVoiceRecordingBar();
        }

        voiceRecordingCancel.addEventListener('click', discardVoiceRecording);

        // WhatsApp-style swipe-to-cancel, layered on top of the explicit
        // trash button above (kept - a gesture-only cancel is easy to
        // miss and harder to discover). Dragging the pill left past
        // VOICE_SLIDE_CANCEL_PX discards the recording exactly like
        // tapping trash; released short of that, it snaps back and
        // recording continues untouched.
        var VOICE_SLIDE_CANCEL_PX = 80;
        var voiceSlideStartX = null;

        function resetVoiceSlidePill() {
            voiceSlideStartX = null;
            voiceRecordingPill.style.transform = '';
            voiceSlideCancelHint.classList.add('hidden');
            voiceSlideCancelHint.classList.remove('flex');
        }

        voiceRecordingPill.addEventListener('pointerdown', function (event) {
            voiceSlideStartX = event.clientX;
            voiceRecordingPill.setPointerCapture(event.pointerId);
        });

        voiceRecordingPill.addEventListener('pointermove', function (event) {
            if (voiceSlideStartX === null) {
                return;
            }
            var deltaX = event.clientX - voiceSlideStartX;
            if (deltaX >= 0) {
                // Only a leftward drag means anything here.
                voiceRecordingPill.style.transform = '';
                voiceSlideCancelHint.classList.add('hidden');
                voiceSlideCancelHint.classList.remove('flex');
                return;
            }
            voiceRecordingPill.style.transform = 'translateX(' + Math.max(deltaX, -VOICE_SLIDE_CANCEL_PX * 1.5) + 'px)';
            var pastHintThreshold = deltaX < -20;
            voiceSlideCancelHint.classList.toggle('hidden', !pastHintThreshold);
            voiceSlideCancelHint.classList.toggle('flex', pastHintThreshold);
        });

        voiceRecordingPill.addEventListener('pointerup', function (event) {
            if (voiceSlideStartX === null) {
                return;
            }
            var deltaX = event.clientX - voiceSlideStartX;
            resetVoiceSlidePill();
            if (deltaX < -VOICE_SLIDE_CANCEL_PX) {
                discardVoiceRecording();
            }
        });

        voiceRecordingPill.addEventListener('pointercancel', resetVoiceSlidePill);

        voiceRecordingPause.addEventListener('click', function () {
            if (!mediaRecorder) return;
            if (voiceIsPaused) {
                mediaRecorder.resume();
                voiceRecordingStartedAt = Date.now();
                voiceIsPaused = false;
                voiceRecordingPause.textContent = 'Pause';
                voiceRecordingDot.classList.remove('opacity-30');
                updateVoiceWaveform();
            } else {
                mediaRecorder.pause();
                voicePausedElapsedMs += Date.now() - voiceRecordingStartedAt;
                voiceIsPaused = true;
                voiceRecordingPause.textContent = 'Resume';
                voiceRecordingDot.classList.add('opacity-30');
                stopVoiceWaveformLoop();
            }
        });

        voiceRecordingSend.addEventListener('click', function () {
            if (!mediaRecorder) return;
            voiceSendImmediately = true;
            if (mediaRecorder.state === 'paused') {
                // MediaRecorder needs to be active to stop and flush
                // cleanly - resuming just long enough to stop is fine
                // since the recording bar is being torn down regardless.
                mediaRecorder.resume();
            }
            mediaRecorder.stop();
        });

        // In-app camera (Instagram/WhatsApp-style) - never the OS's own
        // native camera picker. Tap the shutter for a photo; press and
        // hold it to record a video (mirrors that same gesture in both
        // apps). A capture lands on a review screen first - "Use" is
        // what actually feeds it into the normal pendingAttachments
        // preview/send flow, same as a Gallery pick.
        var cameraModal = document.getElementById('camera-modal');
        var cameraVideo = document.getElementById('camera-video');
        var cameraCanvas = document.getElementById('camera-canvas');
        var cameraClose = document.getElementById('camera-close');
        var cameraFlip = document.getElementById('camera-flip');
        var cameraShutter = document.getElementById('camera-shutter');
        var cameraCaptureControls = document.getElementById('camera-capture-controls');
        var cameraReviewControls = document.getElementById('camera-review-controls');
        var cameraReview = document.getElementById('camera-review');
        var cameraReviewImg = document.getElementById('camera-review-img');
        var cameraReviewVideo = document.getElementById('camera-review-video');
        var cameraRetake = document.getElementById('camera-retake');
        var cameraUse = document.getElementById('camera-use');
        var cameraRecordIndicator = document.getElementById('camera-record-indicator');
        var cameraRecordTimeEl = document.getElementById('camera-record-time');
        var cameraLockHint = document.getElementById('camera-lock-hint');
        var cameraStopRecordingBtn = document.getElementById('camera-stop-recording');

        var CAMERA_HOLD_THRESHOLD_MS = 350;
        var CAMERA_LOCK_SWIPE_PX = 60;
        var cameraStream = null;
        var cameraFacingMode = 'user';
        var cameraRecorder = null;
        var cameraRecordedChunks = [];
        var cameraCapturedFile = null;
        var cameraHoldTimer = null;
        var cameraIsRecording = false;
        var cameraRecordStartedAt = null;
        var cameraRecordTimerInterval = null;
        var cameraRecordingLocked = false;
        var cameraHoldStartY = null;

        function startCameraStream(facingMode) {
            return navigator.mediaDevices.getUserMedia({ video: { facingMode: facingMode }, audio: true }).then(function (stream) {
                cameraStream = stream;
                cameraVideo.srcObject = stream;
            });
        }

        function stopCameraStream() {
            if (cameraStream) {
                cameraStream.getTracks().forEach(function (track) { track.stop(); });
                cameraStream = null;
            }
        }

        function resetCameraReview() {
            cameraCapturedFile = null;
            cameraReview.classList.add('hidden');
            cameraReview.classList.remove('flex');
            cameraReviewImg.classList.add('hidden');
            cameraReviewVideo.classList.add('hidden');
            cameraReviewVideo.pause();
            cameraReviewVideo.removeAttribute('src');
            cameraCaptureControls.classList.remove('hidden');
            cameraCaptureControls.classList.add('flex');
            cameraReviewControls.classList.add('hidden');
            cameraReviewControls.classList.remove('flex');
            cameraVideo.classList.remove('hidden');
        }

        function openCameraModal() {
            cameraFacingMode = 'user';
            cameraModal.classList.remove('hidden');
            cameraModal.classList.add('flex');
            resetCameraReview();

            startCameraStream(cameraFacingMode).catch(function (error) {
                console.error('Camera permission denied or unavailable', error);
                showError('Could not access the camera. Check your browser permissions.');
                closeCameraModal();
            });

            navigator.mediaDevices.enumerateDevices().then(function (devices) {
                var cameraCount = devices.filter(function (d) { return d.kind === 'videoinput'; }).length;
                cameraFlip.classList.toggle('hidden', cameraCount < 2);
                cameraFlip.classList.toggle('flex', cameraCount >= 2);
            }).catch(function () {});
        }

        function closeCameraModal() {
            // A locked hands-free recording keeps running with no finger
            // held down at all - closing the modal mid-recording must
            // not leave the MediaRecorder (and the stream it's reading)
            // running in the background with nothing on screen anymore.
            if (cameraIsRecording) {
                stopVideoRecording();
            }
            stopCameraStream();
            cameraModal.classList.add('hidden');
            cameraModal.classList.remove('flex');
            resetCameraReview();
        }

        cameraClose.addEventListener('click', closeCameraModal);
        cameraModal.addEventListener('click', function (event) {
            if (event.target === cameraModal) closeCameraModal();
        });

        cameraFlip.addEventListener('click', function () {
            stopCameraStream();
            cameraFacingMode = cameraFacingMode === 'user' ? 'environment' : 'user';
            startCameraStream(cameraFacingMode).catch(function () {});
        });

        function showCameraReview() {
            cameraVideo.classList.add('hidden');
            cameraReview.classList.remove('hidden');
            cameraReview.classList.add('flex');
            cameraCaptureControls.classList.add('hidden');
            cameraCaptureControls.classList.remove('flex');
            cameraReviewControls.classList.remove('hidden');
            cameraReviewControls.classList.add('flex');
        }

        function takePhoto() {
            cameraCanvas.width = cameraVideo.videoWidth;
            cameraCanvas.height = cameraVideo.videoHeight;
            cameraCanvas.getContext('2d').drawImage(cameraVideo, 0, 0);
            cameraCanvas.toBlob(function (blob) {
                if (!blob) return;
                cameraCapturedFile = new File([blob], 'photo-' + Date.now() + '.jpg', { type: 'image/jpeg' });
                cameraReviewImg.src = URL.createObjectURL(blob);
                cameraReviewImg.classList.remove('hidden');
                showCameraReview();
            }, 'image/jpeg', 0.9);
        }

        function startVideoRecording() {
            if (!cameraStream) return;
            cameraRecordedChunks = [];
            cameraRecordingLocked = false;
            cameraRecorder = new MediaRecorder(cameraStream);
            cameraRecorder.ondataavailable = function (event) {
                if (event.data.size > 0) cameraRecordedChunks.push(event.data);
            };
            cameraRecorder.onstop = function () {
                var blob = new Blob(cameraRecordedChunks, { type: 'video/webm' });
                cameraCapturedFile = new File([blob], 'video-' + Date.now() + '.webm', { type: 'video/webm' });
                cameraReviewVideo.src = URL.createObjectURL(blob);
                cameraReviewVideo.classList.remove('hidden');
                showCameraReview();
            };
            cameraRecorder.start();
            cameraIsRecording = true;
            cameraRecordStartedAt = Date.now();
            cameraRecordIndicator.classList.remove('hidden');
            cameraRecordIndicator.classList.add('flex');
            cameraLockHint.classList.remove('hidden');
            cameraLockHint.classList.add('flex');
            cameraRecordTimerInterval = setInterval(function () {
                var seconds = Math.round((Date.now() - cameraRecordStartedAt) / 1000);
                cameraRecordTimeEl.textContent = Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
            }, 500);
        }

        function stopVideoRecording() {
            if (cameraRecorder && cameraRecorder.state === 'recording') {
                cameraRecorder.stop();
            }
            cameraIsRecording = false;
            cameraRecordingLocked = false;
            clearInterval(cameraRecordTimerInterval);
            cameraRecordIndicator.classList.add('hidden');
            cameraRecordIndicator.classList.remove('flex');
            cameraLockHint.classList.add('hidden');
            cameraLockHint.classList.remove('flex');
            cameraStopRecordingBtn.classList.add('hidden');
            cameraStopRecordingBtn.classList.remove('flex');
            cameraShutter.classList.remove('hidden');
        }

        // WhatsApp/Instagram-style hands-free recording: while holding
        // the shutter down, dragging up past CAMERA_LOCK_SWIPE_PX "locks"
        // recording on - releasing your finger afterward no longer stops
        // it, a real Stop button takes over instead. Without this, a
        // long recording could only ever be held by physically keeping a
        // finger pressed the entire time.
        function lockRecording() {
            if (cameraRecordingLocked || !cameraIsRecording) return;
            cameraRecordingLocked = true;
            cameraLockHint.classList.add('hidden');
            cameraLockHint.classList.remove('flex');
            cameraShutter.classList.add('hidden');
            cameraStopRecordingBtn.classList.remove('hidden');
            cameraStopRecordingBtn.classList.add('flex');
        }

        cameraShutter.addEventListener('pointerdown', function (event) {
            cameraHoldStartY = event.clientY;
            // Real bug avoided here: without capturing the pointer, once
            // a drag moves outside this small 64px button's own bounds
            // (exactly what "slide up to lock" means), it stops
            // receiving move/up events entirely - they'd fire on
            // whatever's now under the finger instead. Capturing keeps
            // every event for this gesture routed here regardless of
            // where the finger actually ends up.
            cameraShutter.setPointerCapture(event.pointerId);
            cameraHoldTimer = setTimeout(function () {
                cameraHoldTimer = null;
                startVideoRecording();
            }, CAMERA_HOLD_THRESHOLD_MS);
        });

        cameraShutter.addEventListener('pointermove', function (event) {
            if (!cameraIsRecording || cameraRecordingLocked || cameraHoldStartY === null) {
                return;
            }
            if (cameraHoldStartY - event.clientY > CAMERA_LOCK_SWIPE_PX) {
                lockRecording();
            }
        });

        cameraShutter.addEventListener('pointerup', function () {
            cameraHoldStartY = null;
            if (cameraHoldTimer) {
                clearTimeout(cameraHoldTimer);
                cameraHoldTimer = null;
                takePhoto();
            } else if (cameraIsRecording && !cameraRecordingLocked) {
                stopVideoRecording();
            }
            // If locked, releasing does nothing - #camera-stop-recording is
            // now the only way to end it.
        });

        cameraStopRecordingBtn.addEventListener('click', stopVideoRecording);

        cameraRetake.addEventListener('click', function () {
            resetCameraReview();
        });

        cameraUse.addEventListener('click', function () {
            if (cameraCapturedFile) {
                // A camera capture replaces any other pending selection
                // rather than joining it, same reasoning as a voice note.
                pendingAttachments = [];
                addPendingAttachments([cameraCapturedFile], false, null);
            }
            closeCameraModal();
        });

        function openForwardPicker(messageIds) {
            forwardingMessageIds = Array.isArray(messageIds) ? messageIds : [messageIds];
            forwardPicker.innerHTML = '<p class="px-1 py-1 text-xs text-gray-400">Loading conversations&hellip;</p>';
            forwardPicker.classList.remove('hidden');

            axios.get('/api/v1/conversations').then(function (response) {
                forwardPicker.innerHTML = '';
                response.data.data.forEach(function (conversation) {
                    if (conversation.uuid === '{{ $conversation->uuid }}') {
                        return;
                    }
                    var label = conversation.type === 'group' ? conversation.title : (conversation.other_user?.full_name || 'Unknown');
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'block w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-gray-100';
                    btn.textContent = label;
                    btn.dataset.conversationId = conversation.id;
                    forwardPicker.appendChild(btn);
                });
                if (!forwardPicker.children.length) {
                    forwardPicker.innerHTML = '<p class="px-1 py-1 text-xs text-gray-400">No other conversations to forward to.</p>';
                }
            }).catch(function () {
                forwardPicker.innerHTML = '<p class="px-1 py-1 text-xs text-red-500">Could not load conversations.</p>';
            });
        }

        function closeForwardPicker() {
            forwardingMessageIds = [];
            forwardPicker.classList.add('hidden');
            forwardPicker.innerHTML = '';
        }

        forwardPicker.addEventListener('click', function (event) {
            var targetId = event.target.dataset.conversationId;
            if (!targetId || !forwardingMessageIds.length) {
                return;
            }

            // Forwarded in ascending id order so they land in the
            // destination conversation in the same order they were sent
            // here, regardless of the order they were selected/clicked in.
            var orderedIds = forwardingMessageIds.slice().sort(function (a, b) { return a - b; });
            var destinationId = Number(targetId);
            Promise.all(orderedIds.map(function (id) {
                var container = messagesEl.querySelector('[data-message-id="' + id + '"]');
                var isEncrypted = container && container.dataset.encryptionMode === 'e2e';
                if (!isEncrypted) {
                    return axios.post('/api/v1/messages/' + id + '/forward', { conversation_id: destinationId });
                }

                // Can't just have the server copy the ciphertext - it's
                // encrypted under this conversation's key, not the
                // destination's. container.dataset.body already holds
                // the plaintext (decryptMessageInPlace ran before this
                // was rendered) - decrypt-then-reencrypt happens here,
                // client-side, same as an original send.
                var plaintext = container.dataset.body;
                return ensureEncryptionReadyFor(destinationId).then(function (keyInfo) {
                    if (!keyInfo) {
                        return axios.post('/api/v1/messages/' + id + '/forward', { conversation_id: destinationId, body: plaintext, encryption_mode: 'none' });
                    }
                    return MeetE2E.encryptText(plaintext, keyInfo.key).then(function (enc) {
                        return axios.post('/api/v1/messages/' + id + '/forward', {
                            conversation_id: destinationId, body: enc.body, encryption_mode: 'e2e', iv: enc.iv, key_version: keyInfo.version,
                        });
                    });
                });
            })).then(function () {
                clearError();
                closeForwardPicker();
                exitMessageSelectMode();
            }).catch(function (error) {
                console.error('Failed to forward message(s)', error);
                showError('Could not forward the selected message(s).');
            });
        });

        // WhatsApp-style message multi-select: pick "Select messages" from
        // the kebab menu to reveal a checkbox per message and a bulk-action
        // bar in place of the normal thread header, then Star/Forward/Delete
        // every checked message at once via the existing single-message
        // endpoints, one request per id (mirrors the chat list's bulk
        // pattern in index.blade.php).
        var selectedMessageIds = new Set();
        var threadHeader = document.getElementById('chat-thread-header');
        var messageBulkBar = document.getElementById('message-bulk-bar');
        var messageSelectBtn = document.getElementById('message-select-btn');
        var messageBulkCancelBtn = document.getElementById('message-bulk-cancel-btn');
        var messageBulkCountEl = document.getElementById('message-bulk-count');
        var messageBulkStarBtn = document.getElementById('message-bulk-star-btn');
        var messageBulkForwardBtn = document.getElementById('message-bulk-forward-btn');
        var messageBulkDeleteBtn = document.getElementById('message-bulk-delete-btn');

        function updateMessageBulkCount() {
            messageBulkCountEl.textContent = selectedMessageIds.size;
            var hasSelection = selectedMessageIds.size > 0;
            messageBulkStarBtn.disabled = !hasSelection;
            messageBulkForwardBtn.disabled = !hasSelection;
            messageBulkDeleteBtn.disabled = !hasSelection;
        }

        function enterMessageSelectMode() {
            messageSelectModeActive = true;
            threadHeader.classList.add('hidden');
            messageBulkBar.classList.remove('hidden');
            messageBulkBar.classList.add('flex');
            messagesEl.querySelectorAll('.message-select-checkbox').forEach(function (cb) { cb.classList.remove('hidden'); });
        }

        function exitMessageSelectMode() {
            messageSelectModeActive = false;
            selectedMessageIds.clear();
            updateMessageBulkCount();
            threadHeader.classList.remove('hidden');
            messageBulkBar.classList.add('hidden');
            messageBulkBar.classList.remove('flex');
            messagesEl.querySelectorAll('.message-select-checkbox').forEach(function (cb) {
                cb.checked = false;
                cb.classList.add('hidden');
            });
        }

        messageSelectBtn.addEventListener('click', function () {
            document.getElementById('chat-menu-dropdown').classList.add('hidden');
            enterMessageSelectMode();
        });
        messageBulkCancelBtn.addEventListener('click', exitMessageSelectMode);

        messagesEl.addEventListener('change', function (event) {
            var checkbox = event.target.closest('.message-select-checkbox');
            if (!checkbox) {
                return;
            }
            var id = findMessageContainer(checkbox).dataset.messageId;
            if (checkbox.checked) {
                selectedMessageIds.add(id);
            } else {
                selectedMessageIds.delete(id);
            }
            updateMessageBulkCount();
        });

        messageBulkStarBtn.addEventListener('click', function () {
            var ids = Array.from(selectedMessageIds);
            // Star is a toggle server-side (RAS-2 API30) - only call it for
            // messages not already starred, so a mixed selection ends up
            // fully starred instead of flipping already-starred ones off.
            var idsToStar = ids.filter(function (id) {
                var container = messagesEl.querySelector('[data-message-id="' + id + '"]');
                var starBtn = container ? container.querySelector('[data-action="star"]') : null;
                return starBtn && starBtn.textContent.trim() === 'Star';
            });
            Promise.all(idsToStar.map(function (id) {
                return axios.post('/api/v1/messages/' + id + '/star');
            })).then(function () {
                clearError();
                exitMessageSelectMode();
                loadMessages();
            }).catch(function (error) {
                console.error('Failed to star selected messages', error);
                showError('Could not star the selected message(s).');
            });
        });

        messageBulkForwardBtn.addEventListener('click', function () {
            openForwardPicker(Array.from(selectedMessageIds));
        });

        messageBulkDeleteBtn.addEventListener('click', async function () {
            var ids = Array.from(selectedMessageIds);
            var confirmed = await MeetDialog.confirm('Delete ' + ids.length + ' selected message' + (ids.length === 1 ? '' : 's') + ' for you?', { danger: true });
            if (!confirmed) {
                return;
            }
            Promise.all(ids.map(function (id) {
                return axios.delete('/api/v1/messages/' + id, { data: { mode: 'me' } });
            })).then(function () {
                clearError();
                exitMessageSelectMode();
                loadMessages();
            }).catch(function (error) {
                console.error('Failed to delete selected messages', error);
                showError('Could not delete the selected message(s).');
            });
        });

        function closeAllMessageMenus() {
            messagesEl.querySelectorAll('.message-actions-menu.flex').forEach(function (el) {
                el.classList.remove('flex');
                el.classList.add('hidden');
            });
        }

        // Positions the floating card near the tapped bubble (below it,
        // right-aligned for my own messages/left-aligned for others -
        // matching where a real long-press menu would anchor), flipping
        // above the bubble instead if there isn't enough room below, and
        // clamped so it never runs off either edge of the viewport.
        function positionAndShowMessageMenu(menu, bubble, fromMe) {
            menu.style.visibility = 'hidden';
            menu.classList.remove('hidden');
            menu.classList.add('flex');

            var bubbleRect = bubble.getBoundingClientRect();
            var menuRect = menu.getBoundingClientRect();

            var left = fromMe ? bubbleRect.right - menuRect.width : bubbleRect.left;
            left = Math.max(8, Math.min(left, window.innerWidth - menuRect.width - 8));

            var top = bubbleRect.bottom + 6;
            if (top + menuRect.height > window.innerHeight - 8) {
                top = bubbleRect.top - menuRect.height - 6;
            }
            top = Math.max(8, top);

            menu.style.left = left + 'px';
            menu.style.top = top + 'px';
            menu.style.visibility = 'visible';
        }

        // A floating menu is anchored to the bubble's on-screen position
        // at the moment it opened - scrolling the thread would otherwise
        // leave it visually detached from the message it belongs to, so
        // just close it instead of trying to track scroll position.
        messagesEl.addEventListener('scroll', closeAllMessageMenus);

        // Tapping anywhere outside an open menu closes it - excludes
        // clicks on a bubble or inside the menu itself, since those are
        // already handled by messagesEl's own click listener below (both
        // fire for the same click as it bubbles up to document; without
        // this guard, opening a menu here would immediately close it
        // again on the very same click).
        document.addEventListener('click', function (event) {
            if (event.target.closest('.message-actions-menu') || event.target.closest('.message-bubble')) {
                return;
            }
            closeAllMessageMenus();
        });

        messagesEl.addEventListener('click', async function (event) {
            var replyQuote = event.target.closest('[data-jump-to-message-id]');
            if (replyQuote) {
                var target = messagesEl.querySelector('[data-message-id="' + replyQuote.dataset.jumpToMessageId + '"]');
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    target.classList.add('ring-2', 'ring-brand-400', 'rounded-2xl');
                    setTimeout(function () {
                        target.classList.remove('ring-2', 'ring-brand-400', 'rounded-2xl');
                    }, 1500);
                }
                return;
            }

            var button = event.target.closest('[data-action]');
            if (!button) {
                // A single tap on the bubble itself does nothing here -
                // the actions card only opens via a long-press (touch) or
                // double-click (desktop), see below. This is what lets a
                // single tap on a One View bubble actually view it
                // instead of fighting with the actions card opening at
                // the same time.
                return;
            }

            var container = findMessageContainer(button);
            var messageId = container.dataset.messageId;
            var action = button.dataset.action;
            closeAllMessageMenus();

            if (action === 'react') {
                if (button.dataset.active === 'true') {
                    removeReactionFromMessage(messageId);
                } else {
                    reactToMessage(messageId, button.dataset.emoji);
                }
            } else if (action === 'reply') {
                startReply(messageId, container.dataset.body);
            } else if (action === 'forward') {
                openForwardPicker(messageId);
            } else if (action === 'copy') {
                // dataset.body is always the already-decrypted plaintext
                // by this point (see the comment on outer.dataset.body
                // in renderMessage()) - never the raw ciphertext.
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(container.dataset.body || '').catch(function () {
                        showError('Could not copy the message.');
                    });
                }
            } else if (action === 'star') {
                axios.post('/api/v1/messages/' + messageId + '/star').then(function () {
                    clearError();
                    loadMessages();
                }).catch(function (error) {
                    console.error('Failed to star message', error);
                    showError('Could not update star.');
                });
            } else if (action === 'pin') {
                axios.post('/api/v1/messages/' + messageId + '/pin').then(function () {
                    clearError();
                    loadMessages();
                    refreshPinnedBanner();
                }).catch(function (error) {
                    console.error('Failed to pin message', error);
                    showError(error.response?.data?.message || 'Could not pin message.');
                });
            } else if (action === 'info') {
                openMessageInfo(messageId);
            } else if (action === 'delete-me' || action === 'delete-everyone') {
                var mode = action === 'delete-everyone' ? 'everyone' : 'me';
                if (mode === 'everyone' && !(await MeetDialog.confirm('Delete this message for everyone?', { danger: true }))) {
                    return;
                }
                axios.delete('/api/v1/messages/' + messageId, { data: { mode: mode } }).then(function () {
                    clearError();
                    loadMessages();
                }).catch(function (error) {
                    console.error('Failed to delete message', error);
                    showError(error.response?.data?.message || 'Could not delete message.');
                });
            }
        });

        // Swipe-right-to-reply - a fast shortcut alongside the Reply
        // button in the tap-revealed actions row, matching the gesture
        // real chat apps use. touch-pan-y on .message-bubble (see
        // renderMessage) lets vertical scrolling pass through natively;
        // only a mostly-horizontal rightward swipe past the threshold
        // triggers this, so scrolling the thread never misfires it.
        var SWIPE_REPLY_THRESHOLD_PX = 60;
        // Was 10px - tight enough that ordinary hand tremor while
        // holding still (routine on a phone) could silently cancel the
        // long-press with zero feedback, which is a real part of why
        // opening the actions card could feel unresponsive/not-instant:
        // the hold was quietly restarting from zero rather than never
        // registering at all.
        var HOLD_MOVE_CANCEL_PX = 18;
        var PRESS_HOLD_MS = 500;
        var pressHoldTimer = null;
        // Scaled down for the same duration as the hold threshold, so
        // there's a visible "this is registering" cue the whole time a
        // press is being held - previously nothing on screen changed
        // until the full 500ms elapsed, which read as lag rather than a
        // deliberate long-press gesture.
        var pressedBubble = null;
        // Real root cause of the actions card sometimes appearing to
        // "not open" on a touch device: this isn't a timing/animation
        // issue at all - the long-press timer DOES open it correctly,
        // but a genuine tap shortly beforehand (e.g. the accidental-tap
        // check every real user's first touch on a bubble amounts to)
        // plus this touch's own release both fire a synthetic `click`,
        // and two clicks landing close together is exactly what makes a
        // browser synthesize a native `dblclick` DOM event - touch or
        // not. That reaches the desktop-oriented dblclick handler below,
        // which immediately toggles the just-opened menu closed again.
        // Tracked here so that handler can tell "two rapid real mouse
        // clicks" apart from "a touch device's own click synthesis" and
        // ignore the latter.
        var lastTouchAt = 0;

        function setBubblePressed(bubble, pressed) {
            if (pressedBubble && pressedBubble !== bubble) {
                pressedBubble.classList.remove('scale-95');
            }
            if (bubble) {
                bubble.classList.toggle('scale-95', pressed);
            }
            pressedBubble = pressed ? bubble : null;
        }

        function openMenuForBubble(bubble) {
            var container = bubble.closest('[data-message-id]');
            var menu = container.querySelector('.message-actions-menu');
            var wasOpen = menu.classList.contains('flex');
            closeAllMessageMenus();
            if (!wasOpen) {
                var fromMe = container.dataset.senderId === String(currentUserId);
                positionAndShowMessageMenu(menu, bubble, fromMe);
            }
        }

        // The floating actions card only opens via a long-press (touch)
        // or double-click (desktop mouse) - never a plain single tap,
        // which needs to stay free for a bubble's own content (a One
        // View placeholder's "tap to view", or a future tap-to-zoom on
        // regular media) rather than fighting with the actions card
        // opening at the same time.
        messagesEl.addEventListener('touchstart', function (event) {
            lastTouchAt = Date.now();
            var bubble = event.target.closest('.message-bubble');
            clearTimeout(pressHoldTimer);
            pressHoldTimer = null;
            if (!bubble || event.touches.length !== 1) {
                swipeStartX = null;
                setBubblePressed(null, false);
                return;
            }
            swipeStartX = event.touches[0].clientX;
            swipeStartY = event.touches[0].clientY;
            setBubblePressed(bubble, true);

            pressHoldTimer = setTimeout(function () {
                pressHoldTimer = null;
                justSwiped = true; // suppress the click that follows touchend
                setTimeout(function () { justSwiped = false; }, 400);
                openMenuForBubble(bubble);
            }, PRESS_HOLD_MS);
        }, { passive: true });

        messagesEl.addEventListener('touchmove', function (event) {
            if (!pressHoldTimer || swipeStartX === null || event.touches.length !== 1) {
                return;
            }
            var dx = Math.abs(event.touches[0].clientX - swipeStartX);
            var dy = Math.abs(event.touches[0].clientY - swipeStartY);
            if (dx > HOLD_MOVE_CANCEL_PX || dy > HOLD_MOVE_CANCEL_PX) {
                clearTimeout(pressHoldTimer);
                pressHoldTimer = null;
                setBubblePressed(null, false);
            }
        }, { passive: true });

        messagesEl.addEventListener('touchend', function (event) {
            lastTouchAt = Date.now();
            clearTimeout(pressHoldTimer);
            pressHoldTimer = null;
            setBubblePressed(null, false);

            if (swipeStartX === null) {
                return;
            }
            var touch = event.changedTouches[0];
            var dx = touch.clientX - swipeStartX;
            var dy = Math.abs(touch.clientY - swipeStartY);
            swipeStartX = null;

            if (dx > SWIPE_REPLY_THRESHOLD_PX && dy < SWIPE_REPLY_THRESHOLD_PX) {
                var container = findMessageContainer(event.target);
                if (container) {
                    startReply(container.dataset.messageId, container.dataset.body);
                    justSwiped = true;
                    setTimeout(function () { justSwiped = false; }, 300);
                }
            }
        }, { passive: true });

        // Desktop mouse equivalent of the touch long-press above - but
        // ONLY for a genuine mouse double-click. A dblclick DOM event
        // also fires when two of a touch device's own synthetic clicks
        // land close together in time (see lastTouchAt's docblock
        // above) - most simply, a real user single-tapping a bubble
        // (does nothing) shortly before long-pressing it, which is a
        // completely ordinary way to use this UI, not an edge case.
        // Without this guard that combination's dblclick immediately
        // closes the menu the long-press itself just opened.
        messagesEl.addEventListener('dblclick', function (event) {
            if (Date.now() - lastTouchAt < 1000) {
                return;
            }
            var bubble = event.target.closest('.message-bubble');
            if (bubble) {
                event.preventDefault();
                openMenuForBubble(bubble);
            }
        });

        function renderFromCachedMessages() {
            MeetOffline.cachedMessages(conversationId).then(function (messages) {
                if (!messages) {
                    showError('Could not load messages. Check your connection and refresh the page.');
                    return;
                }
                clearError();
                messagesEl.innerHTML = '';
                messages.forEach(appendAndTrack);
                scrollToBottom();
            }).catch(function () {
                showError('Could not load messages. Check your connection and refresh the page.');
            });
        }

        // Mirrors every real (server-confirmed, non-pending) message
        // ever appended to the DOM this page load, in order - reset
        // whenever loadMessages() rebuilds the thread from scratch.
        // appendAndTrack() is the single choke point every message
        // passes through regardless of source (initial load, poll, or
        // a send's own optimistic append), so caching from there covers
        // all three instead of just the initial load.
        var cachedMessagesBuffer = [];

        function loadMessages() {
            axios.get('/api/v1/messages/' + conversationId).then(function (response) {
                clearError();
                messagesEl.innerHTML = '';
                cachedMessagesBuffer = [];
                earliestMyMessageId = null;
                oldestLoadedMessageId = null;
                noMoreOlderMessages = false;
                messageReactionsMap = {};
                return Promise.all(response.data.data.map(function (message) {
                    message.from_me = message.sender_id === currentUserId;
                    return decryptMessageInPlace(message);
                })).then(function (messages) {
                    messages.forEach(appendAndTrack);
                    // The server always returns this page oldest-first
                    // (see MessageRepository::paginateForConversation()),
                    // so the first real (numeric-id) message here is the
                    // oldest one currently loaded - the starting point for
                    // loadOlderMessages() below.
                    var oldestNumeric = messages.find(function (m) { return typeof m.id === 'number'; });
                    oldestLoadedMessageId = oldestNumeric ? oldestNumeric.id : null;
                    scrollToBottom();
                    markRead();
                });
            }).catch(function (error) {
                console.error('Failed to load messages', error);
                if (!navigator.onLine) {
                    renderFromCachedMessages();
                    return;
                }
                showError('Could not load messages. Check your connection and refresh the page.');
            });
        }

        var OLDER_MESSAGES_SCROLL_THRESHOLD_PX = 200;
        var loadingOlderIndicator = null;

        // Real bug fixed here: this chat had NO way at all to reach
        // anything before the most recent 30 messages - loadMessages()
        // only ever fetched the newest page and pollNewMessages() only
        // ever fetches what's NEWER than what's rendered. Scrolling to
        // the top of a long conversation simply hit a wall. This adds
        // the missing other half: scrolling near the top fetches the
        // next page of OLDER messages (before=oldestLoadedMessageId) and
        // prepends them, repeating until the server returns nothing more
        // - the actual beginning of the conversation.
        function loadOlderMessages() {
            if (loadingOlderMessages || noMoreOlderMessages || oldestLoadedMessageId === null) {
                return;
            }
            loadingOlderMessages = true;

            loadingOlderIndicator = document.createElement('p');
            loadingOlderIndicator.className = 'py-1 text-center text-[11px] text-gray-400';
            loadingOlderIndicator.textContent = 'Loading earlier messages…';
            messagesEl.insertBefore(loadingOlderIndicator, messagesEl.firstChild);

            var previousScrollHeight = messagesEl.scrollHeight;

            axios.get('/api/v1/messages/' + conversationId, { params: { before: oldestLoadedMessageId } }).then(function (response) {
                var page = response.data.data;
                if (page.length === 0) {
                    noMoreOlderMessages = true;
                    return;
                }
                return Promise.all(page.map(function (message) {
                    message.from_me = message.sender_id === currentUserId;
                    return decryptMessageInPlace(message);
                })).then(function (messages) {
                    // Oldest-first from the server - insert in that same
                    // order right at the top so the thread stays
                    // chronological, not reversed.
                    messages.forEach(function (message) {
                        if (message.id && messagesEl.querySelector('[data-message-id="' + message.id + '"]')) {
                            return;
                        }
                        messagesEl.insertBefore(renderMessage(message), messagesEl.firstChild);
                        if (typeof message.id === 'number') {
                            oldestLoadedMessageId = oldestLoadedMessageId === null ? message.id : Math.min(oldestLoadedMessageId, message.id);
                        }
                    });
                });
            }).catch(function (error) {
                console.error('Failed to load older messages', error);
            }).then(function () {
                if (loadingOlderIndicator) {
                    loadingOlderIndicator.remove();
                    loadingOlderIndicator = null;
                }
                // Keeps whatever the user was looking at fixed in place -
                // without this, prepending content above the viewport
                // yanks the visible messages down the screen.
                messagesEl.scrollTop = messagesEl.scrollHeight - previousScrollHeight + messagesEl.scrollTop;
                loadingOlderMessages = false;
            });
        }

        messagesEl.addEventListener('scroll', function () {
            if (messagesEl.scrollTop <= OLDER_MESSAGES_SCROLL_THRESHOLD_PX) {
                loadOlderMessages();
            }
        });

        function appendAndTrack(message) {
            // A poll cycle can fire between a send request being issued
            // and its own optimistic-append callback running (lastMessageId
            // isn't bumped until that callback does), so the same just-sent
            // message can be fetched and appended twice - once by the poll,
            // once by the send's own .then(). Guard against that here
            // rather than trying to win the race, using the same
            // data-message-id convention already relied on elsewhere
            // (see findMessageContainer).
            if (message.id && messagesEl.querySelector('[data-message-id="' + message.id + '"]')) {
                lastMessageId = Math.max(lastMessageId, message.id || 0);
                return;
            }
            messagesEl.appendChild(renderMessage(message));
            lastMessageId = Math.max(lastMessageId, message.id || 0);

            if (message.from_me && typeof message.id === 'number') {
                earliestMyMessageId = earliestMyMessageId === null ? message.id : Math.min(earliestMyMessageId, message.id);
            }

            // Pending (locally-queued, not-yet-sent) messages have a
            // string "pending-..." id, not a real numeric one - cached
            // post-decryption, same trust model as the private key
            // itself already living in IndexedDB.
            if (typeof message.id === 'number' && window.MeetOffline) {
                cachedMessagesBuffer.push(message);
                MeetOffline.cacheMessages(conversationId, cachedMessagesBuffer).catch(function () {});
            }
        }

        function pollNewMessages() {
            axios.get('/api/v1/messages/' + conversationId, { params: { after: lastMessageId } }).then(function (response) {
                clearError();
                updateMyMessageStatuses();
                syncReactions();
                if (response.data.data.length === 0) {
                    return;
                }
                return Promise.all(response.data.data.map(function (message) {
                    message.from_me = message.sender_id === currentUserId;
                    return decryptMessageInPlace(message);
                })).then(function (messages) {
                    messages.forEach(appendAndTrack);
                    scrollToBottom();
                    markRead();
                });
            }).catch(function (error) {
                console.error('Failed to poll messages', error);
                showError('Lost connection to the server. New messages may not appear until you refresh.');
            });
        }

        // Advances ticks (sent -> delivered -> read) on my own messages
        // already rendered - piggybacks on the existing message-poll
        // cadence rather than adding a second setInterval, matching this
        // app's poll-only architecture (FR-053).
        function updateMyMessageStatuses() {
            if (earliestMyMessageId === null) {
                return;
            }
            axios.get('/api/v1/conversations/' + conversationId + '/message-statuses', { params: { since: earliestMyMessageId } })
                .then(function (response) {
                    Object.keys(response.data.data).forEach(function (id) {
                        var info = response.data.data[id];
                        var container = messagesEl.querySelector('[data-message-id="' + id + '"]');
                        if (!container) return;

                        var tick = container.querySelector('.message-status-tick');
                        if (tick && tick.dataset.status !== info.delivery_status) {
                            renderTick(tick, info.delivery_status);
                        }

                        // Lets the sender see "Opened" the moment the
                        // recipient actually views it, while staying on
                        // this same page - previously only a full reload
                        // (navigating away and back) picked this up.
                        if (info.one_view_consumed) {
                            var oneViewEl = container.querySelector('.message-one-view-content');
                            if (oneViewEl && oneViewEl.textContent.indexOf('Opened') === -1) {
                                oneViewEl.replaceWith(buildOneViewOpenedElement());
                            }
                        }
                    });
                }).catch(function () {});
        }

        function lastSeenLabel(user) {
            if (user.presence_status === 'online') return 'Online';
            if (!user.last_seen_at) return 'Offline';

            var seconds = Math.max(0, Math.round((Date.now() - new Date(user.last_seen_at).getTime()) / 1000));
            if (seconds < 60) return 'last seen just now';
            if (seconds < 3600) return 'last seen ' + Math.round(seconds / 60) + ' minute' + (Math.round(seconds / 60) === 1 ? '' : 's') + ' ago';
            if (seconds < 86400) return 'last seen ' + Math.round(seconds / 3600) + ' hour' + (Math.round(seconds / 3600) === 1 ? '' : 's') + ' ago';
            return 'last seen ' + Math.round(seconds / 86400) + ' day' + (Math.round(seconds / 86400) === 1 ? '' : 's') + ' ago';
        }

        function pollConversationMeta() {
            axios.get('/api/v1/conversations/' + conversationId).then(function (response) {
                var data = response.data.data;
                typingEl.classList.toggle('hidden', !data.is_typing);
                var presenceEl = document.getElementById('presence-status');
                if (presenceEl && data.other_user) {
                    presenceEl.textContent = lastSeenLabel(data.other_user);
                }
                // The only other place this ever got read from was the
                // (usually-closed) picker's own dataset attribute - the
                // other participant had no way to find out disappearing
                // messages had been turned on/off/changed short of
                // reloading the page. Keeps the picker's own dataset in
                // sync too, so reopening it here doesn't show a stale
                // selection either.
                var disappearingPicker = document.getElementById('disappearing-picker');
                if (disappearingPicker && typeof data.disappearing_messages_seconds !== 'undefined') {
                    disappearingPicker.dataset.disappearingSeconds = (data.disappearing_messages_seconds === null) ? '' : String(data.disappearing_messages_seconds);
                    updateDisappearingIndicator(data.disappearing_messages_seconds);
                }
            }).catch(function (error) {
                console.error('Failed to poll conversation meta', error);
            });
            refreshPinnedBanner();
        }

        var PINNED_PREVIEW_TYPES = {
            image: 'Photo', video: 'Video', audio: 'Voice message',
            file: 'Document', location: 'Location', contact: 'Contact', one_view: 'Photo/video',
        };

        // Only one message can ever be pinned per chat now (see
        // MessageService::togglePin()) - this piggybacks on the existing
        // conversation-meta poll (FR-053's poll-only architecture, no new
        // setInterval) so pinning/unpinning shows up for every participant
        // without anyone needing to reload, not just the person who acted.
        function refreshPinnedBanner() {
            axios.get('/api/v1/conversations/' + conversationId + '/pinned').then(function (response) {
                var pinned = response.data.data;
                if (!pinned.length) {
                    currentPinnedMessageId = null;
                    pinnedBanner.classList.add('hidden');
                    pinnedBanner.classList.remove('flex');
                    return;
                }
                var message = pinned[0];
                currentPinnedMessageId = message.id;
                decryptMessageInPlace(message).then(function (decrypted) {
                    // Same "still the currently pinned one" check after
                    // the decrypt round-trip - a fast unpin/re-pin
                    // shouldn't let a stale response win the render.
                    if (currentPinnedMessageId !== message.id) {
                        return;
                    }
                    var preview = decrypted.message_type === 'text'
                        ? (decrypted.body || 'Message')
                        : (PINNED_PREVIEW_TYPES[decrypted.message_type] || 'Message');
                    pinnedBannerText.textContent = preview;
                    pinnedBanner.classList.remove('hidden');
                    pinnedBanner.classList.add('flex');
                });
            }).catch(function (error) {
                console.error('Failed to load pinned message', error);
            });
        }

        pinnedBanner.addEventListener('click', function (event) {
            if (pinnedBannerUnpin.contains(event.target) || !currentPinnedMessageId) {
                return;
            }
            var container = messagesEl.querySelector('[data-message-id="' + currentPinnedMessageId + '"]');
            if (container) {
                container.scrollIntoView({ behavior: 'smooth', block: 'center' });
                container.classList.add('ring-2', 'ring-brand-400');
                setTimeout(function () { container.classList.remove('ring-2', 'ring-brand-400'); }, 1500);
            }
        });

        pinnedBannerUnpin.addEventListener('click', function (event) {
            event.stopPropagation();
            if (!currentPinnedMessageId) {
                return;
            }
            axios.post('/api/v1/messages/' + currentPinnedMessageId + '/pin').then(function () {
                refreshPinnedBanner();
                loadMessages();
            }).catch(function () {
                showError('Could not unpin message.');
            });
        });

        function markRead() {
            axios.post('/api/v1/conversations/' + conversationId + '/read').catch(function () {});
        }

        function scrollToBottom() {
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }

        function sendTyping(isTyping) {
            axios.post('/api/v1/conversations/' + conversationId + '/typing', { is_typing: isTyping }).catch(function () {});
        }

        var COMPOSER_MAX_HEIGHT_PX = 128; // matches max-h-32 on the textarea

        // The textarea is box-sizing: border-box (Tailwind's global
        // default), so its own border needs to be added on top of
        // scrollHeight (which only ever measures content+padding,
        // never border) - skipping that is exactly what made the box
        // render visibly taller than needed from the very first
        // keystroke in an earlier version of this function (border
        // was double-counted the other way, forcing content to shrink
        // by border-width and the scrollbar to show a pixel early).
        // Toggling overflow-y between hidden/auto (rather than leaving
        // overflow-y-auto on permanently) is what stops the scrollbar
        // from showing before content genuinely exceeds the max.
        function autoGrowComposer() {
            composerInput.style.height = 'auto';
            var borderHeight = composerInput.offsetHeight - composerInput.clientHeight;
            var contentHeight = composerInput.scrollHeight + borderHeight;
            var exceeds = contentHeight > COMPOSER_MAX_HEIGHT_PX;
            composerInput.style.height = Math.min(contentHeight, COMPOSER_MAX_HEIGHT_PX) + 'px';
            composerInput.style.overflowY = exceeds ? 'auto' : 'hidden';
        }

        composerInput.addEventListener('input', function () {
            if (!typingSendTimer) {
                sendTyping(true);
                typingSendTimer = setTimeout(function () { typingSendTimer = null; }, 2000);
            }
            clearTimeout(typingStopTimer);
            typingStopTimer = setTimeout(function () { sendTyping(false); }, typingTimeoutMs);
            // Re-enable Send as soon as there's something to send again -
            // it gets disabled the instant a send goes out (see below) so
            // a rapid double-tap can't fire the request twice.
            sendBtn.disabled = !composerInput.value.trim() && pendingAttachments.length === 0;
            autoGrowComposer();
        });

        // Enter sends (matching WhatsApp Web); Shift+Enter inserts an
        // actual newline instead, now that this is a real textarea.
        composerInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                composerForm.requestSubmit();
            }
        });

        // Pasting a copied image (Ctrl/Cmd+V - e.g. from a screenshot
        // tool or another app) attaches it the same way picking one from
        // Gallery would, rather than silently doing nothing.
        composerInput.addEventListener('paste', function (event) {
            var items = event.clipboardData && event.clipboardData.items;
            if (!items) return;

            var imageFiles = [];
            for (var i = 0; i < items.length; i++) {
                if (items[i].type && items[i].type.indexOf('image/') === 0) {
                    var file = items[i].getAsFile();
                    if (file) imageFiles.push(file);
                }
            }

            if (imageFiles.length > 0) {
                event.preventDefault();
                addPendingAttachments(imageFiles, false, null);
            }
        });

        function finishSend(promise) {
            return promise.then(function (response) {
                clearError();
                var message = response.data.data;
                message.from_me = true;
                return decryptMessageInPlace(message).then(function (decrypted) {
                    appendAndTrack(decrypted);
                    scrollToBottom();
                    composerInput.value = '';
                    autoGrowComposer();
                    sendTyping(false);
                    cancelReply();
                    clearPendingAttachment();
                });
            }).catch(function (error) {
                console.error('Failed to send message', error);
                showError(error.response && error.response.data && error.response.data.message
                    ? error.response.data.message
                    : 'Your message was not sent. Check your connection and try again.');
            }).finally(function () {
                isSending = false;
                sendBtn.disabled = !composerInput.value.trim() && pendingAttachments.length === 0;
            });
        }

        composerForm.addEventListener('submit', function (event) {
            event.preventDefault();
            // Guards against a rapid double/triple-tap on Send firing the
            // request more than once before the button's own disabled
            // state has had a chance to take effect.
            if (isSending) {
                return;
            }
            var body = composerInput.value.trim();
            if (!body && pendingAttachments.length === 0) {
                return;
            }
            isSending = true;
            sendBtn.disabled = true;

            if (pendingAttachments.length > 0) {
                var isOneView = oneViewToggle.checked;
                var uploads = pendingAttachments.map(function (item) {
                    var formData = new FormData();
                    formData.append('file', item.file);
                    formData.append('is_voice_note', item.isVoiceNote ? '1' : '0');
                    if (item.durationSeconds) {
                        formData.append('duration_seconds', item.durationSeconds);
                    }
                    return axios.post('/api/v1/media', formData);
                });

                // Each attachment becomes its own message (mirrors how a
                // real message row only ever holds one media_id) - sent
                // sequentially, not in parallel, so multiple picks land
                // in the thread in the same order they were selected
                // rather than racing each other.
                var chain = Promise.resolve();
                uploads.forEach(function (uploadPromise, index) {
                    chain = chain.then(function () {
                        return uploadPromise.then(function (uploadResponse) {
                            var media = uploadResponse.data.data;
                            var messageType = isOneView ? 'one_view' : (media.media_type === 'document' ? 'file' : media.media_type);
                            var thisBody = index === uploads.length - 1 ? (body || null) : null;

                            return finishSend(replyingToId
                                ? axios.post('/api/v1/messages/' + replyingToId + '/reply', { message_type: messageType, media_id: media.id, body: thisBody })
                                : axios.post('/api/v1/messages', { conversation_id: conversationId, message_type: messageType, media_id: media.id, body: thisBody }));
                        });
                    });
                });
                chain.catch(function (error) {
                    console.error('Failed to upload attachment', error);
                    showError(error.response && error.response.data && error.response.data.message
                        ? error.response.data.message
                        : 'Could not upload the attachment.');
                    isSending = false;
                    sendBtn.disabled = false;
                });

                return;
            }

            var replyId = replyingToId;
            var replyBody = replyingToBody;

            if (!navigator.onLine) {
                // Attachments still require a real upload and stay
                // genuinely impossible offline (out of scope even under
                // "offline-first" - there's no getting around needing the
                // network for that) - only a plain text send/reply can
                // be queued here.
                var localId = 'pending-' + Date.now();
                var pendingEl = renderMessage({
                    id: localId, body: body, from_me: true, message_type: 'text', encryption_mode: 'none',
                    sender_id: currentUserId, created_at: new Date().toISOString(), reply_to: null, reactions: [],
                });
                pendingEl.dataset.localId = localId;
                pendingEl.classList.add('opacity-60');
                var pendingLabel = document.createElement('p');
                pendingLabel.className = 'mt-0.5 text-[10px] text-gray-400';
                pendingLabel.textContent = 'Waiting to send…';
                pendingEl.appendChild(pendingLabel);
                messagesEl.appendChild(pendingEl);
                scrollToBottom();

                MeetOffline.enqueue({ type: 'send_message', conversationId: conversationId, body: body, replyId: replyId, replyBody: replyBody, localId: localId }).catch(function (error) {
                    console.error('Failed to queue offline message', error);
                });

                composerInput.value = '';
                cancelReply();
                isSending = false;
                sendBtn.disabled = true;
                return;
            }

            finishSend(ensureEncryptionReadyFor(conversationId).then(function (keyInfo) {
                if (!keyInfo) {
                    return replyId
                        ? axios.post('/api/v1/messages/' + replyId + '/reply', { body: body })
                        : axios.post('/api/v1/messages', { conversation_id: conversationId, body: body });
                }

                return MeetE2E.encryptText(body, keyInfo.key).then(function (enc) {
                    var payload = { body: enc.body, encryption_mode: 'e2e', iv: enc.iv, key_version: keyInfo.version };

                    if (!replyId) {
                        payload.conversation_id = conversationId;
                        return axios.post('/api/v1/messages', payload);
                    }
                    if (!replyBody) {
                        return axios.post('/api/v1/messages/' + replyId + '/reply', payload);
                    }

                    // The reply's own quoted preview - re-encrypted with
                    // its own fresh IV (never reuse the body's IV).
                    return MeetE2E.encryptText(replyBody.slice(0, 100), keyInfo.key).then(function (previewEnc) {
                        payload.reply_preview = previewEnc.body;
                        payload.reply_preview_iv = previewEnc.iv;
                        return axios.post('/api/v1/messages/' + replyId + '/reply', payload);
                    });
                });
            }));
        });

        // Replays this conversation's own queued sends (see the
        // composer submit handler's offline branch above) - only ever
        // handles action.conversationId === conversationId, leaving
        // anything queued for a different conversation for whenever
        // that conversation's own page is next opened while online (see
        // offline.js's docblock for this bounded-scope tradeoff).
        window.MeetOfflineHandlers.send_message = function (action) {
            if (action.conversationId !== conversationId) {
                return Promise.reject(new Error('queued for a different conversation'));
            }

            return ensureEncryptionReadyFor(conversationId).then(function (keyInfo) {
                function send(payload) {
                    return action.replyId
                        ? axios.post('/api/v1/messages/' + action.replyId + '/reply', payload)
                        : axios.post('/api/v1/messages', Object.assign({ conversation_id: conversationId }, payload));
                }

                if (!keyInfo) {
                    return send({ body: action.body });
                }

                return MeetE2E.encryptText(action.body, keyInfo.key).then(function (enc) {
                    var payload = { body: enc.body, encryption_mode: 'e2e', iv: enc.iv, key_version: keyInfo.version };
                    if (!action.replyId || !action.replyBody) {
                        return send(payload);
                    }
                    return MeetE2E.encryptText(action.replyBody.slice(0, 100), keyInfo.key).then(function (previewEnc) {
                        payload.reply_preview = previewEnc.body;
                        payload.reply_preview_iv = previewEnc.iv;
                        return send(payload);
                    });
                });
            }).then(function (response) {
                var pendingEl = messagesEl.querySelector('[data-local-id="' + action.localId + '"]');
                if (pendingEl) pendingEl.remove();
                var message = response.data.data;
                message.from_me = true;
                return decryptMessageInPlace(message).then(function (decrypted) {
                    appendAndTrack(decrypted);
                    scrollToBottom();
                });
            });
        };

        loadMessages();
        if (navigator.onLine && window.MeetOffline) {
            MeetOffline.processQueue(window.MeetOfflineHandlers);
        }
        e2eAvailable().then(function (available) {
            if (!available) return;
            ensureEncryptionReadyFor(conversationId).then(function (keyInfo) {
                if (keyInfo) migrateHistoryToEncrypted(keyInfo);
            });
        });
        setInterval(pollNewMessages, pollMs);
        pollConversationMeta();
        setInterval(pollConversationMeta, {{ (int) $pollingConfig['conversation_list_ms'] }});

        // Halving pollMs itself (1000ms -> 500ms) was tried already and
        // reverted - it doubles per-tab request volume and saturated
        // the server (see config.php's own comment). This is the
        // "different approach" that comment calls for instead: a
        // message push (already sent to every recipient on every send,
        // see MessageService::pushToRecipients()) reaches this same
        // open tab's Firebase foreground listener (push-notifications.js)
        // and triggers an immediate poll the instant it lands, rather
        // than waiting for pollMs's next tick - the interval above
        // keeps running unchanged as the reliable fallback for a
        // delayed/dropped push or Firebase simply not being configured.
        window.addEventListener('meet-push-message', function (event) {
            var data = event.detail || {};
            if (data.type === 'message' && String(data.conversation_id) === String(conversationId)) {
                pollNewMessages();
            }
        });
    });
    </script>
</x-layouts.app>
