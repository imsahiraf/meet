<!doctype html>
<html lang="en" class="h-full">
<head>
        <meta name="robots" content="noindex, nofollow">
<meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $meeting->title }} - Meet</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-gray-50">
    <div id="meeting-app" data-token="{{ $guestSession->session_token }}" class="flex h-screen flex-col overflow-hidden bg-white">

        <div id="waiting-panel" class="flex flex-1 flex-col items-center justify-center gap-3 p-8 text-center">
            <p class="text-base font-medium text-gray-900">Waiting for the host to let you in&hellip;</p>
        </div>

        <div id="ended-panel" class="hidden flex-1 flex-col items-center justify-center gap-4 p-8 text-center">
            <span class="flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 text-gray-500">
                <x-icons.phone-end class="h-7 w-7" />
            </span>
            <p class="text-lg font-semibold text-gray-900" id="ended-message">This meeting has ended.</p>
            <a href="{{ url('/') }}" class="rounded-full bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-brand-700">
                Go to homepage
            </a>
        </div>

        @if (! $meeting->organizer_id)
            <div id="share-link-banner" class="flex flex-wrap items-center gap-2 border-b border-gray-100 bg-brand-50 px-4 py-2 text-sm text-brand-700">
                <span>Share this link to invite others:</span>
                <code id="share-link-value" class="rounded bg-white px-2 py-0.5 text-xs text-gray-700">{{ url('/j/'.$meeting->meeting_code) }}</code>
                <button type="button" id="share-link-copy" class="rounded-lg border border-brand-200 px-2 py-0.5 text-xs font-medium text-brand-700 hover:bg-brand-100">Copy</button>
            </div>
        @endif

        {{-- Below `lg` this uses the same Google-Meet-style mobile layout
             as the registered-user room (room.blade.php): full-bleed video
             grid, a floating icon-only bottom control bar, and People as a
             slide-up overlay instead of a permanent sidebar. Every control
             keeps the same id either way, so the script below needs no changes. --}}
        <div id="room-panel" class="hidden flex-1 flex-col overflow-hidden">
            <div class="border-b border-gray-100 px-4 py-2 lg:flex lg:items-center lg:justify-between lg:gap-3">
                <p class="truncate text-sm font-semibold text-gray-900">{{ $meeting->title }} <span class="font-normal text-gray-400">(Guest)</span></p>
                <div id="room-controls" class="fixed inset-x-0 bottom-0 z-20 flex flex-wrap items-center justify-center gap-1 bg-black/70 px-3 py-3 backdrop-blur-sm lg:static lg:shrink-0 lg:justify-end lg:gap-2 lg:bg-transparent lg:px-0 lg:py-0 lg:backdrop-blur-none">
                    <button type="button" id="mobile-panel-toggle-btn" title="People" class="flex items-center gap-1.5 rounded-full p-3 text-white hover:bg-white/10 lg:hidden">
                        <x-icons.contacts class="h-5 w-5" />
                    </button>
                    <button type="button" id="screen-share-btn" title="Share screen" class="flex items-center gap-1.5 rounded-full p-3 text-white hover:bg-white/10 lg:rounded-lg lg:p-1.5 lg:px-3 lg:text-gray-500 lg:hover:bg-gray-100">
                        <x-icons.screen-share class="h-5 w-5 lg:h-4 lg:w-4" /><span class="hidden lg:inline">Share screen</span>
                    </button>
                    <button type="button" id="whiteboard-btn" title="Whiteboard" class="flex items-center gap-1.5 rounded-full p-3 text-white hover:bg-white/10 lg:rounded-lg lg:p-1.5 lg:px-3 lg:text-gray-500 lg:hover:bg-gray-100">
                        <x-icons.pencil class="h-5 w-5 lg:h-4 lg:w-4" /><span class="hidden lg:inline">Whiteboard</span>
                    </button>
                    {{-- Only ever relevant for the guest who created this
                         meeting themselves - they're its host (see
                         MeetingService::guestCreateInstant()) - toggled
                         via updateHostControlsVisibility() below,
                         mirroring the registered room's own lock button. --}}
                    <button type="button" id="mx-cc-btn" title="Captions" aria-pressed="false" class="flex items-center gap-1.5 rounded-full p-3 text-white hover:bg-white/10 lg:rounded-lg lg:p-1.5 lg:px-3 lg:text-gray-500 lg:hover:bg-gray-100">
                        <svg class="h-5 w-5 lg:h-4 lg:w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M10.5 10a2 2 0 1 0 0 4M16.5 10a2 2 0 1 0 0 4"/></svg><span class="hidden lg:inline">Captions</span>
                    </button>
                    <button type="button" id="lock-btn" title="Lock" class="hidden items-center gap-1.5 rounded-full p-3 text-white hover:bg-white/10 lg:rounded-lg lg:p-1.5 lg:px-3 lg:text-gray-500 lg:hover:bg-gray-100">
                        <x-icons.lock class="h-5 w-5 lg:h-4 lg:w-4" /><span class="hidden lg:inline">Lock</span>
                    </button>
                    <button type="button" id="mute-self-btn" title="Mute" class="flex items-center gap-1.5 rounded-full p-3 text-white hover:bg-white/10 lg:rounded-lg lg:p-1.5 lg:px-3 lg:text-gray-500 lg:hover:bg-gray-100">
                        <x-icons.mic class="h-5 w-5 lg:h-4 lg:w-4" /><span class="hidden lg:inline">Mute</span>
                    </button>
                    {{-- Mirrors room.blade.php's identical button/menu -
                         hidden until setSinkId() support is confirmed. --}}
                    <button type="button" id="audio-output-btn" title="Speaker" class="hidden items-center gap-1.5 rounded-full p-3 text-white hover:bg-white/10 lg:rounded-lg lg:p-1.5 lg:px-3 lg:text-gray-500 lg:hover:bg-gray-100">
                        <x-icons.volume class="h-5 w-5 lg:h-4 lg:w-4" /><span class="hidden lg:inline">Speaker</span>
                    </button>
                    <div id="audio-output-menu" class="fixed bottom-20 right-4 z-30 hidden w-52 flex-col rounded-xl bg-white py-1 shadow-2xl"></div>
                    <button type="button" id="camera-self-btn" title="Camera off" class="flex items-center gap-1.5 rounded-full p-3 text-white hover:bg-white/10 lg:rounded-lg lg:p-1.5 lg:px-3 lg:text-gray-500 lg:hover:bg-gray-100">
                        <x-icons.video class="h-5 w-5 lg:h-4 lg:w-4" /><span class="hidden lg:inline">Camera off</span>
                    </button>
                    {{-- Mirrors room.blade.php's identical button - hidden
                         until a genuine second camera is confirmed. --}}
                    <button type="button" id="flip-camera-btn" title="Flip camera" class="hidden items-center gap-1.5 rounded-full p-3 text-white hover:bg-white/10 lg:rounded-lg lg:p-1.5 lg:px-3 lg:text-gray-500 lg:hover:bg-gray-100">
                        <x-icons.flip-camera class="h-5 w-5 lg:h-4 lg:w-4" /><span class="hidden lg:inline">Flip camera</span>
                    </button>
                    <button type="button" id="leave-btn" title="Leave" class="flex items-center gap-1.5 rounded-full bg-gray-700 p-3 text-white hover:bg-gray-600 lg:rounded-lg lg:bg-gray-800 lg:p-1.5 lg:px-3 lg:hover:bg-gray-900">
                        <x-icons.phone-end class="h-5 w-5 lg:h-4 lg:w-4" /><span class="hidden lg:inline">Leave</span>
                    </button>
                </div>
            </div>
            <div class="flex flex-1 overflow-hidden">
                <div id="video-grid" class="tiles-1 grid flex-1 gap-2 overflow-y-auto bg-gray-900 p-2 pb-20 lg:pb-2"></div>

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

                <div id="room-side-panel" class="fixed inset-0 z-30 hidden w-full flex-col bg-white lg:static lg:inset-auto lg:z-auto lg:flex lg:w-64 lg:shrink-0 lg:border-l lg:border-gray-100">
                    <div class="flex items-center justify-between border-b border-gray-100 px-3 py-2">
                        <p class="text-xs font-semibold text-gray-500">People</p>
                        <button type="button" id="room-side-panel-close-btn" class="text-gray-400 hover:text-gray-600 lg:hidden">&times;</button>
                    </div>
                    <ul id="participant-list" class="flex-1 divide-y divide-gray-100 overflow-y-auto text-sm"></ul>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.MeetingRoomConfig = @json(config('meetings'));
    </script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var cfg = window.MeetingRoomConfig;
        var token = document.getElementById('meeting-app').dataset.token;
        var apiBase = '/api/v1/meetings/guest/' + token;

        var copyBtn = document.getElementById('share-link-copy');
        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                var value = document.getElementById('share-link-value').textContent;
                navigator.clipboard.writeText(value).then(function () {
                    copyBtn.textContent = 'Copied!';
                    setTimeout(function () { copyBtn.textContent = 'Copy'; }, 2000);
                });
            });
        }

        // Mobile People panel toggle (below lg: it's a slide-up overlay
        // instead of the desktop's always-visible sidebar).
        (function () {
            var toggleBtn = document.getElementById('mobile-panel-toggle-btn');
            var sidePanel = document.getElementById('room-side-panel');
            var closeBtn = document.getElementById('room-side-panel-close-btn');
            if (!toggleBtn || !sidePanel) {
                return;
            }
            toggleBtn.addEventListener('click', function () {
                sidePanel.classList.remove('hidden');
                sidePanel.classList.add('flex');
            });
            closeBtn.addEventListener('click', function () {
                sidePanel.classList.add('hidden');
                sidePanel.classList.remove('flex');
            });
        })();

        var panels = {
            waiting: document.getElementById('waiting-panel'),
            ended: document.getElementById('ended-panel'),
            room: document.getElementById('room-panel'),
        };

        function showPanel(name) {
            Object.keys(panels).forEach(function (key) {
                panels[key].classList.toggle('hidden', key !== name);
                panels[key].classList.toggle('flex', key === name);
            });
        }

        var state = {
            myParticipantId: null,
            role: null,
            localStream: null,
            screenStream: null,
            peers: {},
            signalCursor: { signal: 0, ice: 0 },
            timers: {},
            whiteboard: null,
            wbPageId: null,
            wbTool: 'pen',
            wbDrawing: false,
            wbStrokePoints: [],
            wbCursor: { after_id: 0, since: null },
        };

        // Best-effort graceful leave on tab close/navigation-away - see
        // partials/room-script.blade.php's identical handler for why
        // (same reasoning applies to the guest room).
        window.addEventListener('pagehide', function () {
            if (!state.myParticipantId) return;
            var data = new FormData();
            data.append('_token', document.querySelector('meta[name="csrf-token"]').content);
            navigator.sendBeacon(apiBase + '/leave', data);
        });

        function clearTimer(name) {
            if (state.timers[name]) { clearInterval(state.timers[name]); state.timers[name] = null; }
        }

        var videoGrid = document.getElementById('video-grid');

        // Same adaptive layout as the registered-user room (see
        // partials/room-script.blade.php's identical helper) - .tiles-3/
        // .tiles-many are defined once in app.css, shared by both rooms.
        var VIDEO_GRID_LAYOUT_CLASSES = ['tiles-1', 'tiles-2', 'tiles-3', 'tiles-4', 'tiles-many', 'grid-cols-1', 'grid-cols-2', 'grid-rows-2', 'lg:grid-rows-1', 'lg:grid-cols-2'];
        function updateVideoGridLayout() {
            var count = videoGrid.children.length;
            videoGrid.classList.remove.apply(videoGrid.classList, VIDEO_GRID_LAYOUT_CLASSES);
            if (count <= 1) {
                videoGrid.classList.add('tiles-1', 'grid-cols-1');
            } else if (count === 2) {
                videoGrid.classList.add('tiles-2', 'grid-cols-1', 'grid-rows-2', 'lg:grid-rows-1', 'lg:grid-cols-2');
            } else if (count === 3) {
                videoGrid.classList.add('tiles-3');
            } else if (count === 4) {
                videoGrid.classList.add('tiles-4', 'grid-cols-2', 'grid-rows-2');
            } else {
                videoGrid.classList.add('tiles-many');
            }
        }

        function tileFor(id, label, stream, muted) {
            var existing = document.getElementById('tile-' + id);
            if (existing) { existing.querySelector('video').srcObject = stream; return; }
            var wrap = document.createElement('div');
            wrap.id = 'tile-' + id;
            wrap.className = 'relative overflow-hidden rounded-lg bg-black';
            var video = document.createElement('video');
            video.autoplay = true; video.playsInline = true; video.muted = !!muted; video.srcObject = stream;
            video.className = 'h-full w-full object-cover';
            if (selectedAudioOutputId && video.setSinkId) {
                video.setSinkId(selectedAudioOutputId).catch(function () {});
            }
            wrap.appendChild(video);
            var labelEl = document.createElement('span');
            labelEl.className = 'absolute bottom-1 left-1 rounded bg-black/60 px-1.5 py-0.5 text-xs text-white';
            labelEl.textContent = label;
            wrap.appendChild(labelEl);
            videoGrid.appendChild(wrap);
            updateVideoGridLayout();
        }

        function removeTile(id) {
            var el = document.getElementById('tile-' + id);
            if (el) { el.remove(); updateVideoGridLayout(); }
        }

        function ensurePeerConnection(peerId) {
            if (state.peers[peerId]) return state.peers[peerId].pc;
            var pc = new RTCPeerConnection({ iceServers: cfg.ice_servers, iceCandidatePoolSize: cfg.ice_candidate_pool_size || 0 });
            state.peers[peerId] = { pc: pc, remoteDescSet: false, iceQueue: [], reconnectTimer: null };
            if (state.localStream) state.localStream.getTracks().forEach(function (t) { pc.addTrack(t, state.localStream); });
            pc.onicecandidate = function (e) {
                if (e.candidate) axios.post(apiBase + '/ice', { receiver_participant_id: peerId, candidate: JSON.stringify(e.candidate) }).catch(function () {});
            };
            pc.ontrack = function (e) { tileFor(peerId, 'Participant ' + peerId, e.streams[0], false); };
            // See Meetings/resources/views/partials/room-script.blade.php's
            // identical reconnection logic for the rationale.
            pc.oniceconnectionstatechange = function () {
                var peer = state.peers[peerId];
                if (!peer) return;
                if (pc.iceConnectionState === 'connected' || pc.iceConnectionState === 'completed') {
                    clearTimeout(peer.reconnectTimer);
                } else if (pc.iceConnectionState === 'disconnected') {
                    clearTimeout(peer.reconnectTimer);
                    peer.reconnectTimer = setTimeout(function () { restartPeerIce(peerId); }, 3000);
                } else if (pc.iceConnectionState === 'failed') {
                    clearTimeout(peer.reconnectTimer);
                    restartPeerIce(peerId);
                }
            };
            return pc;
        }

        function restartPeerIce(peerId) {
            var peer = state.peers[peerId];
            if (!peer || Number(state.myParticipantId) >= Number(peerId)) return;
            peer.pc.createOffer({ iceRestart: true }).then(function (offer) {
                return peer.pc.setLocalDescription(offer);
            }).then(function () {
                return axios.post(apiBase + '/offer', { receiver_participant_id: peerId, sdp: JSON.stringify(peer.pc.localDescription) });
            }).catch(function () {});
        }

        function teardownPeer(peerId) {
            if (state.peers[peerId]) { try { state.peers[peerId].pc.close(); } catch (e) {} delete state.peers[peerId]; }
            removeTile(peerId);
        }

        function connectToPeer(peerId) {
            if (Number(state.myParticipantId) >= Number(peerId)) return;
            var pc = ensurePeerConnection(peerId);
            pc.createOffer().then(function (offer) { return pc.setLocalDescription(offer); }).then(function () {
                return axios.post(apiBase + '/offer', { receiver_participant_id: peerId, sdp: JSON.stringify(pc.localDescription) });
            }).catch(function () {});
        }

        var knownIds = {};

        // Only ever true for the guest who created this meeting
        // themselves (see MeetingService::guestCreateInstant()) - a
        // guest who merely joined someone else's meeting is always a
        // plain participant, same as any registered non-host attendee.
        function updateHostControlsVisibility() {
            var isHostOrCoHost = state.role === 'host' || state.role === 'co_host';
            var lockBtn = document.getElementById('lock-btn');
            lockBtn.classList.toggle('hidden', !isHostOrCoHost);
            lockBtn.classList.toggle('flex', isHostOrCoHost);
        }

        function pollRoster() {
            axios.get(apiBase + '/roster').then(function (response) {
                var roster = response.data.data;
                var seen = {};
                var list = document.getElementById('participant-list');
                list.innerHTML = '';

                roster.forEach(function (p) {
                    seen[p.id] = true;
                    if (p.id == state.myParticipantId && p.role !== state.role) {
                        state.role = p.role;
                        updateHostControlsVisibility();
                    }
                    if (p.id != state.myParticipantId && !knownIds[p.id]) { knownIds[p.id] = true; connectToPeer(p.id); }
                    var li = document.createElement('li');
                    li.className = 'px-3 py-2';
                    li.textContent = p.display_name + (p.id == state.myParticipantId ? ' (you)' : '');
                    list.appendChild(li);
                });

                Object.keys(knownIds).forEach(function (id) {
                    if (!seen[id]) { delete knownIds[id]; teardownPeer(id); }
                });
            }).catch(function () {});
        }

        function pollSignals() {
            axios.get(apiBase + '/signals', { params: { after_signal_id: state.signalCursor.signal, after_ice_id: state.signalCursor.ice } }).then(function (response) {
                var data = response.data.data;
                data.signals.forEach(function (signal) {
                    state.signalCursor.signal = Math.max(state.signalCursor.signal, signal.id);
                    var peerId = signal.sender_participant_id;
                    var payload = JSON.parse(signal.payload);
                    var peer = state.peers[peerId];
                    if (signal.signal_type === 'offer') {
                        var pc = ensurePeerConnection(peerId);
                        peer = state.peers[peerId];
                        pc.setRemoteDescription(new RTCSessionDescription(payload)).then(function () {
                            peer.remoteDescSet = true;
                            peer.iceQueue.forEach(function (c) { pc.addIceCandidate(new RTCIceCandidate(c)).catch(function () {}); });
                            peer.iceQueue = [];
                            return pc.createAnswer();
                        }).then(function (answer) { return pc.setLocalDescription(answer); }).then(function () {
                            return axios.post(apiBase + '/answer', { receiver_participant_id: peerId, sdp: JSON.stringify(pc.localDescription) });
                        }).catch(function () {});
                    } else if (signal.signal_type === 'answer' && peer) {
                        peer.pc.setRemoteDescription(new RTCSessionDescription(payload)).then(function () {
                            peer.remoteDescSet = true;
                            peer.iceQueue.forEach(function (c) { peer.pc.addIceCandidate(new RTCIceCandidate(c)).catch(function () {}); });
                            peer.iceQueue = [];
                        }).catch(function () {});
                    }
                });
                data.ice_candidates.forEach(function (item) {
                    state.signalCursor.ice = Math.max(state.signalCursor.ice, item.id);
                    var peerId = item.sender_participant_id;
                    var candidate = JSON.parse(item.candidate);
                    var peer = state.peers[peerId];
                    if (peer && peer.remoteDescSet) peer.pc.addIceCandidate(new RTCIceCandidate(candidate)).catch(function () {});
                    else if (peer) peer.iceQueue.push(candidate);
                });
            }).catch(function () {});
        }

        function enterRoom() {
            showPanel('room');
            navigator.mediaDevices.getUserMedia({ audio: true, video: true }).then(function (stream) {
                state.localStream = stream;
                tileFor('self', 'You', stream, true);
                updateFlipCameraAvailability();
                updateAudioOutputAvailability();
            }).catch(function () {});

            pollRoster();
            state.timers.roster = setInterval(pollRoster, cfg.incoming_call_poll_ms);
            state.timers.signals = setInterval(pollSignals, cfg.signal_poll_ms);
        }

        var entered = false;

        function pollStatus() {
            axios.get(apiBase + '/status').then(function (response) {
                var data = response.data.data;

                if (data.status === 'active' && !entered) {
                    entered = true;
                    axios.get(apiBase + '/roster').then(function (rosterResponse) {
                        state.myParticipantId = rosterResponse.data.meta.my_participant_id;
                        enterRoom();
                    });
                } else if (data.status === 'rejected' || data.status === 'expired') {
                    Object.keys(state.timers).forEach(clearTimer);
                    document.getElementById('ended-message').textContent = data.status === 'rejected' ? 'The host did not admit you.' : 'Your guest session has expired.';
                    showPanel('ended');
                }
            }).catch(function () {});
        }

        // Only the label span's text updates - the button also holds an
        // icon (see the Google-Meet-style mobile control bar in
        // guest-room.blade.php) that a plain `this.textContent =` would
        // otherwise silently wipe out.
        function setToolbarLabel(btn, text) {
            btn.title = text;
            btn.querySelector('span').textContent = text;
        }

        document.getElementById('mute-self-btn').addEventListener('click', function () {
            if (!state.localStream) return;
            var track = state.localStream.getAudioTracks()[0];
            if (!track) return;
            track.enabled = !track.enabled;
            setToolbarLabel(this, track.enabled ? 'Mute' : 'Unmute');
            axios.patch(apiBase + '/media', { microphone_enabled: track.enabled }).catch(function () {});
        });

        document.getElementById('camera-self-btn').addEventListener('click', function () {
            if (!state.localStream) return;
            var track = state.localStream.getVideoTracks()[0];
            if (!track) return;
            track.enabled = !track.enabled;
            setToolbarLabel(this, track.enabled ? 'Camera off' : 'Camera on');
            axios.patch(apiBase + '/media', { camera_enabled: track.enabled }).catch(function () {});
        });

        // ---- Camera switch (front/back) - mirrors
        // partials/room-script.blade.php's identical block. ----
        var flipCameraBtn = document.getElementById('flip-camera-btn');
        var currentFacingMode = 'user';

        function updateFlipCameraAvailability() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
                flipCameraBtn.classList.add('hidden');
                return;
            }
            navigator.mediaDevices.enumerateDevices().then(function (devices) {
                var cameraCount = devices.filter(function (d) { return d.kind === 'videoinput'; }).length;
                flipCameraBtn.classList.toggle('hidden', cameraCount < 2);
            }).catch(function () {
                flipCameraBtn.classList.add('hidden');
            });
        }

        flipCameraBtn.addEventListener('click', function () {
            if (!state.localStream || state.screenStream) {
                return;
            }
            var nextFacing = currentFacingMode === 'user' ? 'environment' : 'user';

            navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: nextFacing } }, audio: false }).then(function (stream) {
                var newTrack = stream.getVideoTracks()[0];
                var oldTrack = state.localStream.getVideoTracks()[0];

                Object.keys(state.peers).forEach(function (peerId) {
                    var sender = state.peers[peerId].pc.getSenders().find(function (s) { return s.track && s.track.kind === 'video'; });
                    if (sender) sender.replaceTrack(newTrack);
                });

                if (oldTrack) {
                    state.localStream.removeTrack(oldTrack);
                    oldTrack.stop();
                }
                state.localStream.addTrack(newTrack);
                currentFacingMode = nextFacing;
            }).catch(function () {});
        });

        // ---- Audio output (Speaker/Earpiece/Bluetooth/wired headset) -
        // mirrors partials/room-script.blade.php's identical block. ----
        var audioOutputBtn = document.getElementById('audio-output-btn');
        var audioOutputMenu = document.getElementById('audio-output-menu');
        var selectedAudioOutputId = null;

        function updateAudioOutputAvailability() {
            var probe = document.createElement('video');
            audioOutputBtn.classList.toggle('hidden', typeof probe.setSinkId !== 'function');
        }

        function friendlyOutputLabel(device, isFirst) {
            if (/bluetooth/i.test(device.label)) return 'Bluetooth headset';
            if (/headset|headphone|wired|usb/i.test(device.label)) return 'Headset';
            if (/speaker/i.test(device.label)) return 'Speaker';
            if (/ear-?piece|receiver/i.test(device.label)) return 'Earpiece';
            if (isFirst) return 'Default';
            return device.label || 'Audio output';
        }

        function applyAudioOutput(deviceId) {
            selectedAudioOutputId = deviceId;
            videoGrid.querySelectorAll('video').forEach(function (el) {
                if (el.setSinkId) el.setSinkId(deviceId).catch(function () {});
            });
        }

        function closeAudioOutputMenu() {
            audioOutputMenu.classList.add('hidden');
            audioOutputMenu.classList.remove('flex');
        }

        audioOutputBtn.addEventListener('click', function () {
            if (audioOutputMenu.classList.contains('flex')) {
                closeAudioOutputMenu();
                return;
            }
            navigator.mediaDevices.enumerateDevices().then(function (devices) {
                var outputs = devices.filter(function (d) { return d.kind === 'audiooutput'; });
                if (!outputs.length) return;

                audioOutputMenu.innerHTML = '';
                outputs.forEach(function (device, index) {
                    var isActive = selectedAudioOutputId ? device.deviceId === selectedAudioOutputId : index === 0;
                    var option = document.createElement('button');
                    option.type = 'button';
                    option.className = 'flex items-center justify-between px-4 py-3 text-left text-sm font-medium ' + (isActive ? 'text-brand-700' : 'text-gray-700');
                    option.textContent = friendlyOutputLabel(device, index === 0);
                    if (isActive) {
                        var check = document.createElement('span');
                        check.textContent = '✓';
                        option.appendChild(check);
                    }
                    option.addEventListener('click', function () {
                        applyAudioOutput(device.deviceId);
                        closeAudioOutputMenu();
                    });
                    audioOutputMenu.appendChild(option);
                });

                audioOutputMenu.classList.remove('hidden');
                audioOutputMenu.classList.add('flex');
            }).catch(function () {});
        });

        document.getElementById('lock-btn').addEventListener('click', function () {
            var locking = this.querySelector('span').textContent === 'Lock';
            setToolbarLabel(this, locking ? 'Unlock' : 'Lock');
            // Real bug fixed here (mirrors room-script.blade.php): the
            // label above is `hidden lg:inline` - invisible on mobile -
            // so without this, tapping Lock gave zero visible feedback
            // on a phone even though the API call itself succeeded.
            this.classList.toggle('room-toolbar-btn--active', locking);
            this.querySelector('svg').outerHTML = locking
                ? '<svg class="h-5 w-5 lg:h-4 lg:w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="9" rx="2"></rect><path d="M8 11V7a4 4 0 0 1 7.5-1.9"></path></svg>'
                : '<svg class="h-5 w-5 lg:h-4 lg:w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="9" rx="1.8"></rect><path d="M8 11V7.5a4 4 0 0 1 8 0V11"></path></svg>';
            axios.post(apiBase + '/lock', { locked: locking }).catch(function () {});
        });

        // ---- Screen share - mirrors partials/room-script.blade.php's
        // identical block exactly, just against this room's own
        // guest-scoped apiBase/state.peers. ----
        var screenShareBtn = document.getElementById('screen-share-btn');
        screenShareBtn.addEventListener('click', function () {
            if (state.screenStream) {
                stopScreenShare();
                return;
            }

            navigator.mediaDevices.getDisplayMedia({ video: true }).then(function (stream) {
                state.screenStream = stream;
                var screenTrack = stream.getVideoTracks()[0];

                Object.keys(state.peers).forEach(function (peerId) {
                    var sender = state.peers[peerId].pc.getSenders().find(function (s) { return s.track && s.track.kind === 'video'; });
                    if (sender) sender.replaceTrack(screenTrack);
                });

                screenTrack.onended = stopScreenShare;
                setToolbarLabel(screenShareBtn, 'Stop sharing');
                axios.post(apiBase + '/screen-share/start').catch(function () {});
            }).catch(function () {});
        });

        function stopScreenShare() {
            if (!state.screenStream) return;
            state.screenStream.getTracks().forEach(function (t) { t.stop(); });
            state.screenStream = null;

            var cameraTrack = state.localStream ? state.localStream.getVideoTracks()[0] : null;
            Object.keys(state.peers).forEach(function (peerId) {
                var sender = state.peers[peerId].pc.getSenders().find(function (s) { return s.track && s.track.kind === 'video'; });
                if (sender && cameraTrack) sender.replaceTrack(cameraTrack);
            });

            setToolbarLabel(screenShareBtn, 'Share screen');
            axios.post(apiBase + '/screen-share/stop').catch(function () {});
        }

        function stopAllMedia() {
            if (state.localStream) {
                state.localStream.getTracks().forEach(function (t) { t.stop(); });
                state.localStream = null;
            }
            if (state.screenStream) {
                state.screenStream.getTracks().forEach(function (t) { t.stop(); });
                state.screenStream = null;
            }
        }

        document.getElementById('leave-btn').addEventListener('click', function () {
            axios.post(apiBase + '/leave').then(function () {
                Object.keys(state.timers).forEach(clearTimer);
                stopAllMedia();
                document.getElementById('ended-message').textContent = 'You left the meeting.';
                showPanel('ended');
            }).catch(function () {});
        });

        // ---- Whiteboard ----
        // Mirrors partials/room-script.blade.php's identical block - the
        // only difference is every whiteboard endpoint is reached
        // through apiBase (already guest-session-scoped, see its
        // definition above) instead of the Sanctum-authenticated
        // /api/v1/whiteboards/... paths the registered room calls
        // directly, since a guest has no Sanctum session to use those
        // with. See Modules/Whiteboard/routes/api.php's guest route
        // group and GuestWhiteboardController.
        var whiteboardOverlay = document.getElementById('whiteboard-overlay');
        var wbCanvas = document.getElementById('wb-canvas');
        var wbCtx = wbCanvas.getContext('2d');
        var wbColorInput = document.getElementById('wb-color');

        document.getElementById('whiteboard-btn').addEventListener('click', function () {
            if (!whiteboardOverlay.classList.contains('hidden')) {
                closeWhiteboard();
                return;
            }

            axios.post(apiBase + '/whiteboard').then(function (response) {
                state.whiteboard = response.data.data;
                state.wbPageId = response.data.data.pages[0].id;
                whiteboardOverlay.classList.remove('hidden');
                whiteboardOverlay.classList.add('flex');
                videoGrid.classList.add('hidden');
                wbCtx.clearRect(0, 0, wbCanvas.width, wbCanvas.height);
                pollWhiteboard();
                state.timers.whiteboard = setInterval(pollWhiteboard, cfg.signal_poll_ms);
            }).catch(function () {});
        });

        document.getElementById('wb-close-btn').addEventListener('click', closeWhiteboard);

        function closeWhiteboard() {
            clearTimer('whiteboard');
            whiteboardOverlay.classList.add('hidden');
            whiteboardOverlay.classList.remove('flex');
            videoGrid.classList.remove('hidden');
        }

        document.getElementById('wb-tool-pen').addEventListener('click', function () { setWbTool('pen'); });
        document.getElementById('wb-tool-rectangle').addEventListener('click', function () { setWbTool('rectangle'); });

        function setWbTool(tool) {
            state.wbTool = tool;
            ['pen', 'rectangle'].forEach(function (t) {
                var btn = document.getElementById('wb-tool-' + t);
                btn.classList.toggle('bg-brand-50', t === tool);
                btn.classList.toggle('text-brand-700', t === tool);
                btn.classList.toggle('text-gray-500', t !== tool);
            });
        }

        var wbDrawStart = null;

        // Pointer Events (not mouse-only) - see the identical comment in
        // room-script.blade.php. Touch was completely inert here before.
        wbCanvas.addEventListener('pointerdown', function (event) {
            wbCanvas.setPointerCapture(event.pointerId);
            var rect = wbCanvas.getBoundingClientRect();
            var x = event.clientX - rect.left;
            var y = event.clientY - rect.top;
            state.wbDrawing = true;

            if (state.wbTool === 'pen') {
                state.wbStrokePoints = [[x, y]];
            } else {
                wbDrawStart = [x, y];
            }
        });

        wbCanvas.addEventListener('pointermove', function (event) {
            if (!state.wbDrawing) return;
            var rect = wbCanvas.getBoundingClientRect();
            var x = event.clientX - rect.left;
            var y = event.clientY - rect.top;

            if (state.wbTool === 'pen') {
                state.wbStrokePoints.push([x, y]);
                drawStrokeLocally(state.wbStrokePoints, wbColorInput.value);
            } else {
                wbCtx.clearRect(0, 0, wbCanvas.width, wbCanvas.height);
                renderAllObjects();
                drawRectLocally(wbDrawStart, [x, y], wbColorInput.value);
            }
        });

        function finishWbStroke(event) {
            if (!state.wbDrawing) return;
            state.wbDrawing = false;
            var rect = wbCanvas.getBoundingClientRect();
            var x = event.clientX - rect.left;
            var y = event.clientY - rect.top;

            if (state.wbTool === 'pen' && state.wbStrokePoints.length > 1) {
                postWbObject('pen', { points: state.wbStrokePoints }, wbColorInput.value, 2);
            } else if (state.wbTool === 'rectangle' && wbDrawStart) {
                postWbObject('rectangle', { x: wbDrawStart[0], y: wbDrawStart[1], width: x - wbDrawStart[0], height: y - wbDrawStart[1] }, wbColorInput.value, 2);
            }

            state.wbStrokePoints = [];
            wbDrawStart = null;
        }

        wbCanvas.addEventListener('pointerup', finishWbStroke);
        wbCanvas.addEventListener('pointercancel', function () {
            state.wbDrawing = false;
            state.wbStrokePoints = [];
            wbDrawStart = null;
        });

        function postWbObject(tool, data, color, thickness) {
            axios.post(apiBase + '/whiteboards/' + state.whiteboard.id + '/pages/' + state.wbPageId + '/objects', {
                tool: tool, data: data, color: color, thickness: thickness,
            }).catch(function () {});
        }

        var wbObjects = {};

        function drawStrokeLocally(points, color) {
            wbCtx.strokeStyle = color;
            wbCtx.lineWidth = 2;
            wbCtx.beginPath();
            points.forEach(function (point, i) {
                if (i === 0) wbCtx.moveTo(point[0], point[1]);
                else wbCtx.lineTo(point[0], point[1]);
            });
            wbCtx.stroke();
        }

        function drawRectLocally(start, end, color) {
            wbCtx.strokeStyle = color;
            wbCtx.lineWidth = 2;
            wbCtx.strokeRect(start[0], start[1], end[0] - start[0], end[1] - start[1]);
        }

        function drawObject(object) {
            if (object.tool === 'pen') {
                drawStrokeLocally(object.data.points, object.color || '#111827');
            } else if (object.tool === 'rectangle') {
                wbCtx.strokeStyle = object.color || '#111827';
                wbCtx.lineWidth = object.thickness || 2;
                wbCtx.strokeRect(object.data.x, object.data.y, object.data.width, object.data.height);
            }
        }

        function renderAllObjects() {
            Object.keys(wbObjects).sort(function (a, b) { return a - b; }).forEach(function (id) {
                drawObject(wbObjects[id]);
            });
        }

        function pollWhiteboard() {
            axios.get(apiBase + '/whiteboards/' + state.whiteboard.id + '/pages/' + state.wbPageId + '/objects', {
                params: { after_id: state.wbCursor.after_id, since: state.wbCursor.since },
            }).then(function (response) {
                var data = response.data.data;
                var changed = false;

                data.created.forEach(function (obj) {
                    wbObjects[obj.id] = obj;
                    state.wbCursor.after_id = Math.max(state.wbCursor.after_id, obj.id);
                    changed = true;
                });

                data.restored.forEach(function (obj) { wbObjects[obj.id] = obj; changed = true; });
                data.removed.forEach(function (id) { delete wbObjects[id]; changed = true; });

                state.wbCursor.since = data.server_time;

                if (changed) {
                    wbCtx.clearRect(0, 0, wbCanvas.width, wbCanvas.height);
                    renderAllObjects();
                }
            }).catch(function () {});
        }

        document.getElementById('wb-clear-btn').addEventListener('click', function () {
            axios.post(apiBase + '/whiteboards/' + state.whiteboard.id + '/pages/' + state.wbPageId + '/clear').then(function () {
                wbObjects = {};
                wbCtx.clearRect(0, 0, wbCanvas.width, wbCanvas.height);
            }).catch(function () {});
        });

        document.getElementById('wb-undo-btn').addEventListener('click', function () {
            axios.post(apiBase + '/whiteboards/' + state.whiteboard.id + '/undo').catch(function () {});
        });

        document.getElementById('wb-redo-btn').addEventListener('click', function () {
            axios.post(apiBase + '/whiteboards/' + state.whiteboard.id + '/redo').catch(function () {});
        });

        pollStatus();
        state.timers.statusInitial = setInterval(pollStatus, cfg.incoming_call_poll_ms);
    });
    </script>
    @include('meetings::partials.room-extras')
</body>
</html>
