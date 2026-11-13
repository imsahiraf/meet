<script>
    window.MeetingRoomConfig = @json(config('meetings'));
</script>
<script>
// Phase 5/6 - Meeting room: mesh WebRTC (each participant pair
// negotiates its own peer connection, polled independently - see
// Modules/Meetings/database/migrations/..._create_meeting_signals_table.php
// for why this app doesn't use an SFU/websocket here), meeting chat
// (reuses the existing Chat message API against the meeting's own
// 'meeting'-type conversation), and a polling-synced whiteboard.
//
// Wrapped in DOMContentLoaded because Vite's script tag is
// type="module" (deferred by spec) - window.axios doesn't exist yet
// at the point the parser reaches this plain inline script.
document.addEventListener('DOMContentLoaded', function () {
    var cfg = window.MeetingRoomConfig || { ice_servers: [{ urls: 'stun:stun.l.google.com:19302' }], signal_poll_ms: 1000, incoming_call_poll_ms: 3000 };
    var meetingId = document.getElementById('meeting-app').dataset.meetingId;
    var apiBase = '/api/v1/meetings/' + meetingId;

    var panels = {
        lobby: document.getElementById('lobby-panel'),
        waiting: document.getElementById('waiting-panel'),
        ended: document.getElementById('ended-panel'),
        room: document.getElementById('room-panel'),
    };

    function showPanel(name) {
        Object.keys(panels).forEach(function (key) {
            panels[key].classList.toggle('hidden', key !== name);
            panels[key].classList.toggle('flex', key === name);
        });

        // The app shell's persistent mobile bottom nav (#app-bottom-nav)
        // and this room's own floating control bar are both fixed to
        // the same bottom-0 position - the shell's nav sits at a higher
        // z-index and would otherwise silently cover every in-call
        // control (Mute/Camera/Leave/etc become untappable) while
        // actually in the room. Hidden only for the active call itself,
        // restored for lobby/waiting/ended so normal navigation still
        // works everywhere else on this page.
        var appBottomNav = document.getElementById('app-bottom-nav');
        if (appBottomNav) {
            appBottomNav.classList.toggle('lg:hidden', true);
            appBottomNav.classList.toggle('hidden', name === 'room');
        }
    }

    var state = {
        myParticipantId: null,
        role: 'participant',
        localStream: null,
        screenStream: null,
        chatConversationId: null,
        peers: {}, // participantId -> { pc, tileEl, videoEl }
        signalCursor: { signal: 0, ice: 0 },
        lastChatMessageId: 0,
        whiteboard: null,
        wbPageId: null,
        wbTool: 'pen',
        wbDrawing: false,
        wbStrokePoints: [],
        wbCursor: { after_id: 0, since: null },
        timers: {},
    };

    // Best-effort graceful leave on tab close/navigation-away - the
    // server-side heartbeat/staleness check (see MeetingService::roster())
    // would eventually notice anyway, but this makes it instant instead
    // of waiting out the staleness window. sendBeacon can't set custom
    // headers, so the CSRF token travels in the body instead (Laravel's
    // VerifyCsrfToken checks the _token field there too, not just the
    // X-CSRF-TOKEN header).
    window.addEventListener('pagehide', function () {
        if (!state.myParticipantId) return;
        var data = new FormData();
        data.append('_token', document.querySelector('meta[name="csrf-token"]').content);
        navigator.sendBeacon(apiBase + '/leave', data);
    });

    function clearTimer(name) {
        if (state.timers[name]) {
            clearInterval(state.timers[name]);
            state.timers[name] = null;
        }
    }

    // ---- Lobby ----
    var lobbyPreview = document.getElementById('lobby-preview');
    var lobbyJoinBtn = document.getElementById('lobby-join-btn');
    var lobbyError = document.getElementById('lobby-error');
    var lobbyPasswordEl = document.getElementById('lobby-password');
    var lobbyStream = null;

    // Refreshing the tab while still an active (never-left) participant
    // should rejoin straight into the room, not re-show the lobby and
    // make them click "Join meeting" again - join() is already
    // idempotent server-side for exactly this case (see
    // MeetingService::activeParticipantFor()/join()'s own
    // findActiveParticipant() short-circuit), this just triggers it
    // automatically instead of waiting for a click that would otherwise
    // never come from someone who thinks they're already in the call.
    var alreadyJoined = document.getElementById('meeting-app').dataset.alreadyJoined === '1';
    if (alreadyJoined) {
        lobbyJoinBtn.textContent = 'Rejoining…';
    }

    function attemptJoin() {
        var password = lobbyPasswordEl ? lobbyPasswordEl.value : undefined;
        axios.post(apiBase + '/join', { password: password }).then(function (response) {
            state.localStream = lobbyStream;

            if (response.data.data.status === 'waiting') {
                showPanel('waiting');
                pollMyWaitingStatus();
                return;
            }

            enterRoom(response.data.data.meeting, response.data.data.participant);
        }).catch(function (error) {
            lobbyError.textContent = (error.response && error.response.data && error.response.data.message) || 'Could not join the meeting.';
            lobbyError.classList.remove('hidden');
        });
    }

    // Disabled until getUserMedia settles (resolve or reject) - joining
    // before then would silently add zero local tracks to every mesh
    // peer connection this participant negotiates.
    lobbyJoinBtn.disabled = true;
    navigator.mediaDevices.getUserMedia({ audio: true, video: true }).then(function (stream) {
        lobbyStream = stream;
        lobbyPreview.srcObject = stream;
    }).catch(function () {
        lobbyError.textContent = 'Camera/microphone permission denied. You can still join with audio/video off.';
        lobbyError.classList.remove('hidden');
    }).finally(function () {
        lobbyJoinBtn.disabled = false;
        if (alreadyJoined) {
            attemptJoin();
        }
    });

    lobbyJoinBtn.addEventListener('click', attemptJoin);

    var lobbyShareLinkCopyBtn = document.getElementById('lobby-share-link-copy');
    if (lobbyShareLinkCopyBtn) {
        lobbyShareLinkCopyBtn.addEventListener('click', function () {
            var value = document.getElementById('lobby-share-link-value').textContent;
            navigator.clipboard.writeText(value).then(function () {
                lobbyShareLinkCopyBtn.textContent = 'Copied!';
                setTimeout(function () { lobbyShareLinkCopyBtn.textContent = 'Copy'; }, 2000);
            });
        });
    }

    function pollMyWaitingStatus() {
        state.timers.waitingStatus = setInterval(function () {
            axios.get(apiBase + '/waiting-room/mine').then(function (response) {
                var status = response.data.data.status;

                if (status === 'admitted') {
                    clearTimer('waitingStatus');
                    axios.get(apiBase).then(function (meetingResponse) {
                        axios.get(apiBase + '/participants').then(function (rosterResponse) {
                            var mine = rosterResponse.data.data.find(function (p) { return p.user_id == {{ auth()->id() }}; });
                            enterRoom(meetingResponse.data.data, mine);
                        });
                    });
                } else if (status === 'rejected') {
                    clearTimer('waitingStatus');
                    document.getElementById('ended-message').textContent = 'The host did not admit you to this meeting.';
                    showPanel('ended');
                }
            }).catch(function () {});
        }, cfg.incoming_call_poll_ms);
    }

    // ---- Entering the room ----
    function enterRoom(meeting, participant) {
        state.myParticipantId = participant.id;
        state.role = participant.role;
        state.chatConversationId = meeting.chat_conversation_id;

        showPanel('room');
        renderSelfTile();
        updateHostControlsVisibility();

        pollRoster();
        state.timers.roster = setInterval(pollRoster, cfg.incoming_call_poll_ms);

        pollSignals();
        state.timers.signals = setInterval(pollSignals, cfg.signal_poll_ms);

        pollMeetingStatus();
        state.timers.status = setInterval(pollMeetingStatus, cfg.incoming_call_poll_ms);

        if (state.role === 'host' || state.role === 'co_host') {
            pollWaitingRoom();
            state.timers.waitingRoom = setInterval(pollWaitingRoom, cfg.incoming_call_poll_ms);
        }

        if (state.chatConversationId) {
            loadChatMessages();
            state.timers.chat = setInterval(pollChatMessages, cfg.incoming_call_poll_ms);
        }
    }

    function updateHostControlsVisibility() {
        var isHostOrCoHost = state.role === 'host' || state.role === 'co_host';
        ['end-btn', 'lock-btn'].forEach(function (id) {
            var btn = document.getElementById(id);
            btn.classList.toggle('hidden', !isHostOrCoHost);
            btn.classList.toggle('flex', isHostOrCoHost);
        });
        document.getElementById('waiting-room-list').classList.toggle('hidden', !isHostOrCoHost);
    }

    // ---- Video tiles ----
    var videoGrid = document.getElementById('video-grid');

    // Google-Meet-style adaptive layout - driven by the real tile count
    // instead of a fixed grid-cols-2, which used to leave an empty
    // second cell showing bare background whenever there was only 1
    // tile (read by users as a phantom empty participant panel). 1/2/4
    // are plain Tailwind grid utilities; 3 and 5+ need real
    // grid-template rules that live in app.css as .tiles-3/.tiles-many.
    var VIDEO_GRID_LAYOUT_CLASSES = ['tiles-1', 'tiles-2', 'tiles-3', 'tiles-4', 'tiles-many', 'grid-cols-1', 'grid-cols-2', 'grid-rows-2', 'lg:grid-rows-1'];
    function updateVideoGridLayout() {
        var count = videoGrid.children.length;
        videoGrid.classList.remove.apply(videoGrid.classList, VIDEO_GRID_LAYOUT_CLASSES);
        if (count <= 1) {
            videoGrid.classList.add('tiles-1', 'grid-cols-1');
        } else if (count === 2) {
            videoGrid.classList.add('tiles-2', 'grid-cols-1', 'grid-rows-2', 'lg:grid-rows-1');
            videoGrid.classList.add('lg:grid-cols-2');
        } else if (count === 3) {
            videoGrid.classList.add('tiles-3');
        } else if (count === 4) {
            videoGrid.classList.add('tiles-4', 'grid-cols-2', 'grid-rows-2');
        } else {
            videoGrid.classList.add('tiles-many');
        }
    }

    function tileFor(participantId, label, stream, muted) {
        var existing = document.getElementById('tile-' + participantId);
        if (existing) {
            existing.querySelector('video').srcObject = stream;
            return existing;
        }

        var wrap = document.createElement('div');
        wrap.id = 'tile-' + participantId;
        wrap.className = 'relative overflow-hidden rounded-lg bg-black';

        var video = document.createElement('video');
        video.autoplay = true;
        video.playsInline = true;
        video.muted = !!muted;
        video.srcObject = stream;
        video.className = 'h-full w-full object-cover';
        // A participant joining after an output device was already
        // chosen must play through that same device, not silently fall
        // back to the system default (mirrors the Calls module).
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
        return wrap;
    }

    function renderSelfTile() {
        tileFor('self', 'You', state.localStream, true);
        updateFlipCameraAvailability();
        updateAudioOutputAvailability();
    }

    function removeTile(participantId) {
        var el = document.getElementById('tile-' + participantId);
        if (el) {
            el.remove();
            updateVideoGridLayout();
        }
    }

    // ---- Mesh peer connections ----
    function ensurePeerConnection(peerId) {
        if (state.peers[peerId]) {
            return state.peers[peerId].pc;
        }

        var pc = new RTCPeerConnection({ iceServers: cfg.ice_servers, iceCandidatePoolSize: cfg.ice_candidate_pool_size || 0 });
        state.peers[peerId] = { pc: pc, remoteDescSet: false, iceQueue: [], reconnectTimer: null };

        if (state.localStream) {
            state.localStream.getTracks().forEach(function (track) { pc.addTrack(track, state.localStream); });
        }

        pc.onicecandidate = function (event) {
            if (event.candidate) {
                axios.post(apiBase + '/ice', { receiver_participant_id: peerId, candidate: JSON.stringify(event.candidate) }).catch(function () {});
            }
        };

        pc.ontrack = function (event) {
            tileFor(peerId, 'Participant ' + peerId, event.streams[0], false);
        };

        // Same reconnection reasoning as the 1:1 Calls overlay: give a
        // transient dip a few seconds before restarting ICE on just
        // this one peer connection, rather than tearing down the
        // whole mesh over a momentary blip.
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
        // Same deterministic rule as connectToPeer() - only the lower
        // participant id restarts, so both sides don't race.
        if (!peer || Number(state.myParticipantId) >= Number(peerId)) {
            return;
        }

        peer.pc.createOffer({ iceRestart: true }).then(function (offer) {
            return peer.pc.setLocalDescription(offer);
        }).then(function () {
            return axios.post(apiBase + '/offer', { receiver_participant_id: peerId, sdp: JSON.stringify(peer.pc.localDescription) });
        }).catch(function () {});
    }

    function teardownPeer(peerId) {
        if (state.peers[peerId]) {
            try { state.peers[peerId].pc.close(); } catch (e) { /* already closed */ }
            delete state.peers[peerId];
        }
        removeTile(peerId);
    }

    function connectToPeer(peerId) {
        // Deterministic initiator rule: the lower participant id offers,
        // avoiding a double-offer race when both sides discover each
        // other via their own roster polls at roughly the same time.
        if (Number(state.myParticipantId) >= Number(peerId)) {
            return;
        }

        var pc = ensurePeerConnection(peerId);
        pc.createOffer().then(function (offer) {
            return pc.setLocalDescription(offer);
        }).then(function () {
            return axios.post(apiBase + '/offer', { receiver_participant_id: peerId, sdp: JSON.stringify(pc.localDescription) });
        }).catch(function () {});
    }

    // ---- Roster polling ----
    var participantList = document.getElementById('participant-list');
    var knownParticipantIds = {};

    function pollRoster() {
        axios.get(apiBase + '/participants').then(function (response) {
            var roster = response.data.data;
            var seenIds = {};

            roster.forEach(function (p) {
                seenIds[p.id] = true;

                if (p.id != state.myParticipantId && !knownParticipantIds[p.id]) {
                    knownParticipantIds[p.id] = true;
                    connectToPeer(p.id);
                }
            });

            Object.keys(knownParticipantIds).forEach(function (id) {
                if (!seenIds[id]) {
                    delete knownParticipantIds[id];
                    teardownPeer(id);
                }
            });

            var mine = roster.find(function (p) { return p.id == state.myParticipantId; });
            if (mine) {
                state.role = mine.role;
                updateHostControlsVisibility();
            }

            renderParticipantList(roster);
        }).catch(function () {});
    }

    function renderParticipantList(roster) {
        participantList.innerHTML = '';
        var canManage = state.role === 'host' || state.role === 'co_host';

        roster.forEach(function (p) {
            var li = document.createElement('li');
            li.className = 'flex items-center justify-between gap-2 px-3 py-2';

            var info = document.createElement('div');
            info.className = 'min-w-0';
            info.innerHTML = '<p class="truncate font-medium text-gray-900">' + p.display_name + (p.id == state.myParticipantId ? ' (you)' : '') + '</p>' +
                '<p class="text-xs text-gray-400">' + p.role.replace('_', '-') + (p.hand_raised ? ' &middot; ✋' : '') + '</p>';
            li.appendChild(info);

            if (canManage && p.id != state.myParticipantId) {
                var actions = document.createElement('div');
                actions.className = 'flex shrink-0 gap-1';

                function btn(label, action) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.textContent = label;
                    b.className = 'rounded px-1.5 py-0.5 text-xs text-gray-400 hover:bg-gray-100 hover:text-gray-700';
                    b.addEventListener('click', function () { doParticipantAction(p.id, action); });
                    return b;
                }

                actions.appendChild(btn('Mute', 'mute'));
                actions.appendChild(btn(p.role === 'co_host' ? 'Revoke' : 'Co-host', p.role === 'co_host' ? 'revoke-co-host' : 'co-host'));
                actions.appendChild(btn('Remove', 'remove'));
                li.appendChild(actions);
            }

            participantList.appendChild(li);
        });
    }

    function doParticipantAction(participantId, action) {
        axios.post(apiBase + '/participants/' + participantId + '/' + action).then(pollRoster).catch(function () {});
    }

    // ---- Signaling poll ----
    function pollSignals() {
        axios.get(apiBase + '/signals', {
            params: { after_signal_id: state.signalCursor.signal, after_ice_id: state.signalCursor.ice },
        }).then(function (response) {
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
                        flushIceQueue(peerId);
                        return pc.createAnswer();
                    }).then(function (answer) {
                        return pc.setLocalDescription(answer);
                    }).then(function () {
                        return axios.post(apiBase + '/answer', { receiver_participant_id: peerId, sdp: JSON.stringify(pc.localDescription) });
                    }).catch(function () {});
                } else if (signal.signal_type === 'answer' && peer) {
                    peer.pc.setRemoteDescription(new RTCSessionDescription(payload)).then(function () {
                        peer.remoteDescSet = true;
                        flushIceQueue(peerId);
                    }).catch(function () {});
                }
            });

            data.ice_candidates.forEach(function (item) {
                state.signalCursor.ice = Math.max(state.signalCursor.ice, item.id);
                var peerId = item.sender_participant_id;
                var candidate = JSON.parse(item.candidate);
                var peer = state.peers[peerId];

                if (peer && peer.remoteDescSet) {
                    peer.pc.addIceCandidate(new RTCIceCandidate(candidate)).catch(function () {});
                } else if (peer) {
                    peer.iceQueue.push(candidate);
                }
            });
        }).catch(function () {});
    }

    function flushIceQueue(peerId) {
        var peer = state.peers[peerId];
        if (!peer) return;
        peer.iceQueue.forEach(function (candidate) {
            peer.pc.addIceCandidate(new RTCIceCandidate(candidate)).catch(function () {});
        });
        peer.iceQueue = [];
    }

    // ---- Meeting status poll (detect end/cancel) ----
    function pollMeetingStatus() {
        axios.get(apiBase).then(function (response) {
            var status = response.data.data.status;
            if (status === 'ended' || status === 'cancelled') {
                teardownRoom('This meeting has ended.');
            }
        }).catch(function () {});
    }

    function teardownRoom(message) {
        Object.keys(state.timers).forEach(clearTimer);
        Object.keys(state.peers).forEach(teardownPeer);
        if (state.localStream) {
            state.localStream.getTracks().forEach(function (t) { t.stop(); });
        }
        // Screen share (getDisplayMedia) is a separate track from the
        // camera/mic - without stopping it too, the browser's own
        // "sharing your screen" indicator (and the shared feed itself)
        // kept running after leaving/ending until the tab was closed.
        if (state.screenStream) {
            state.screenStream.getTracks().forEach(function (t) { t.stop(); });
            state.screenStream = null;
        }
        document.getElementById('ended-message').textContent = message;
        showPanel('ended');
    }

    // ---- Waiting room (host) ----
    var waitingRoomList = document.getElementById('waiting-room-list');

    function pollWaitingRoom() {
        axios.get(apiBase + '/waiting-room').then(function (response) {
            var entries = response.data.data;
            waitingRoomList.innerHTML = '';

            if (entries.length === 0) {
                return;
            }

            entries.forEach(function (entry) {
                var row = document.createElement('div');
                row.className = 'flex items-center justify-between gap-2 py-1 text-xs';
                row.innerHTML = '<span>' + entry.display_name + '</span>';

                var admitBtn = document.createElement('button');
                admitBtn.type = 'button';
                admitBtn.textContent = 'Admit';
                admitBtn.className = 'rounded bg-emerald-600 px-2 py-0.5 text-white';
                admitBtn.addEventListener('click', function () {
                    axios.post(apiBase + '/waiting-room/' + entry.id + '/admit').then(pollRoster);
                });

                var rejectBtn = document.createElement('button');
                rejectBtn.type = 'button';
                rejectBtn.textContent = 'Reject';
                rejectBtn.className = 'rounded bg-gray-200 px-2 py-0.5 text-gray-700';
                rejectBtn.addEventListener('click', function () {
                    axios.post(apiBase + '/waiting-room/' + entry.id + '/reject');
                });

                row.appendChild(admitBtn);
                row.appendChild(rejectBtn);
                waitingRoomList.appendChild(row);
            });
        }).catch(function () {});
    }

    // ---- Self controls ----

    // Icon-first toolbar buttons hold a <span> label (hidden below the
    // sm breakpoint) rather than plain text - these helpers keep the
    // label/title in sync with state exactly like the old
    // this.textContent = X assignments did, plus swap the mic/camera
    // icon itself between its on/off variant.
    var MIC_ICON_SVG = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="3" width="6" height="11" rx="3"></rect><path d="M6 11a6 6 0 0 0 12 0M12 17v4M9 21h6"></path></svg>';
    var MIC_OFF_ICON_SVG = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5.2A3 3 0 0 1 15 6.5V11a3 3 0 0 1-.2 1.1"></path><path d="M6 11a6 6 0 0 0 8.4 5.5M17.6 13.5A6 6 0 0 0 18 11"></path><path d="M12 17v4M9 21h6M3 3l18 18"></path></svg>';
    var VIDEO_ICON_SVG = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="12" height="12" rx="2"></rect><path d="m15 10 6-3.5v11L15 14"></path></svg>';
    var VIDEO_OFF_ICON_SVG = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M15 8.5V7a1 1 0 0 0-1-1H8m-4.5.5A1 1 0 0 0 3 7v10a1 1 0 0 0 1 1h9a1 1 0 0 0 1-1v-1.5"></path><path d="m15 10 6-3.5v11L15 14"></path><path d="M3 3l18 18"></path></svg>';
    // Matches resources/views/components/icons/lock.blade.php /
    // unlock.blade.php exactly - this file swaps icons via raw outerHTML,
    // not the Blade component, so the two have to be kept in sync by hand.
    var LOCK_ICON_SVG = '<svg class="h-5 w-5 lg:h-4 lg:w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="9" rx="1.8"></rect><path d="M8 11V7.5a4 4 0 0 1 8 0V11"></path></svg>';
    var UNLOCK_ICON_SVG = '<svg class="h-5 w-5 lg:h-4 lg:w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="9" rx="2"></rect><path d="M8 11V7a4 4 0 0 1 7.5-1.9"></path></svg>';

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
        this.querySelector('svg').outerHTML = track.enabled ? MIC_ICON_SVG : MIC_OFF_ICON_SVG;
        axios.patch(apiBase + '/media', { microphone_enabled: track.enabled }).catch(function () {});
    });

    document.getElementById('camera-self-btn').addEventListener('click', function () {
        if (!state.localStream) return;
        var track = state.localStream.getVideoTracks()[0];
        if (!track) return;
        track.enabled = !track.enabled;
        setToolbarLabel(this, track.enabled ? 'Camera off' : 'Camera on');
        this.querySelector('svg').outerHTML = track.enabled ? VIDEO_ICON_SVG : VIDEO_OFF_ICON_SVG;
        axios.patch(apiBase + '/media', { camera_enabled: track.enabled }).catch(function () {});
    });

    // ---- Camera switch (front/back) ----
    // Same replaceTrack() approach as screen share below - no second SDP
    // negotiation, nobody sees a reconnect. Only worth offering at all on
    // a device with a genuine second camera (checked once tiles are up),
    // matching every other "hide a control that can't do anything" rule
    // in this app rather than showing one that silently does nothing.
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

    // ---- Audio output (Speaker/Earpiece/Bluetooth/wired headset) ----
    // Mirrors Calls' own picker exactly (see app.blade.php) - a real list
    // of this device's actual output devices via setSinkId(), applied to
    // every participant's own tile <video> (each carries its own audio,
    // same reason Calls moved off a single shared element). Hidden
    // outright on a browser without setSinkId support (Safari) rather
    // than showing a button that does nothing.
    var audioOutputBtn = document.getElementById('audio-output-btn');
    var audioOutputMenu = document.getElementById('audio-output-menu');
    var selectedAudioOutputId = null;

    function audioOutputSupported() {
        var probe = document.createElement('video');
        return typeof probe.setSinkId === 'function';
    }

    function updateAudioOutputAvailability() {
        audioOutputBtn.classList.toggle('hidden', !audioOutputSupported());
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

    function openAudioOutputMenu() {
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
    }

    audioOutputBtn.addEventListener('click', function () {
        var isOpen = audioOutputMenu.classList.contains('flex');
        if (isOpen) {
            closeAudioOutputMenu();
        } else {
            openAudioOutputMenu();
        }
    });

    document.getElementById('raise-hand-btn').addEventListener('click', function () {
        var raised = this.querySelector('span').textContent === 'Raise hand';
        setToolbarLabel(this, raised ? 'Lower hand' : 'Raise hand');
        // Real bug fixed here: the label above is `hidden lg:inline` -
        // invisible on mobile - so this highlight is the ONLY feedback a
        // phone user ever sees that the tap actually did anything.
        this.classList.toggle('room-toolbar-btn--active', raised);
        axios.post(apiBase + '/raise-hand', { raised: raised }).catch(function () {});
    });

    document.getElementById('lock-btn').addEventListener('click', function () {
        var locking = this.querySelector('span').textContent === 'Lock';
        setToolbarLabel(this, locking ? 'Unlock' : 'Lock');
        this.classList.toggle('room-toolbar-btn--active', locking);
        this.querySelector('svg').outerHTML = locking ? UNLOCK_ICON_SVG : LOCK_ICON_SVG;
        axios.post(apiBase + '/lock', { locked: locking }).catch(function () {});
    });

    document.getElementById('end-btn').addEventListener('click', async function () {
        if (!await MeetDialog.confirm('End this meeting for everyone?', { danger: true })) return;
        axios.post(apiBase + '/end').then(function () { teardownRoom('You ended the meeting.'); }).catch(function () {});
    });

    document.getElementById('leave-btn').addEventListener('click', function () {
        axios.post(apiBase + '/leave').then(function () { teardownRoom('You left the meeting.'); }).catch(function () {});
    });

    // ---- Screen share ----
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

    // ---- Tabs ----
    document.getElementById('tab-participants').addEventListener('click', function () {
        document.getElementById('participants-panel').classList.remove('hidden');
        document.getElementById('participants-panel').classList.add('flex');
        document.getElementById('chat-panel').classList.add('hidden');
        document.getElementById('chat-panel').classList.remove('flex');
        this.classList.add('border-brand-600', 'text-brand-700');
        this.classList.remove('border-transparent', 'text-gray-500');
        document.getElementById('tab-chat').classList.add('border-transparent', 'text-gray-500');
        document.getElementById('tab-chat').classList.remove('border-brand-600', 'text-brand-700');
    });

    document.getElementById('tab-chat').addEventListener('click', function () {
        document.getElementById('chat-panel').classList.remove('hidden');
        document.getElementById('chat-panel').classList.add('flex');
        document.getElementById('participants-panel').classList.add('hidden');
        this.classList.add('border-brand-600', 'text-brand-700');
        this.classList.remove('border-transparent', 'text-gray-500');
        document.getElementById('tab-participants').classList.add('border-transparent', 'text-gray-500');
        document.getElementById('tab-participants').classList.remove('border-brand-600', 'text-brand-700');
    });

    // ---- Mobile People/Chat panel toggle (below lg: it's a slide-up
    // overlay instead of the desktop's always-visible sidebar - see
    // #room-side-panel's responsive classes in room.blade.php). ----
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

    // ---- Meeting chat (reuses the existing Chat message API) ----
    var meetingMessagesEl = document.getElementById('meeting-messages');
    var meetingChatForm = document.getElementById('meeting-chat-form');
    var meetingChatInput = document.getElementById('meeting-chat-input');

    function appendChatMessage(message) {
        var row = document.createElement('div');
        row.className = 'text-sm';
        row.innerHTML = '<span class="font-medium text-gray-900">' + (message.sender_id == {{ auth()->id() }} ? 'You' : ('User ' + message.sender_id)) + ':</span> <span class="text-gray-700"></span>';
        row.querySelector('span.text-gray-700').textContent = message.body || '';
        meetingMessagesEl.appendChild(row);
        state.lastChatMessageId = Math.max(state.lastChatMessageId, message.id || 0);
        meetingMessagesEl.scrollTop = meetingMessagesEl.scrollHeight;
    }

    function loadChatMessages() {
        axios.get('/api/v1/messages/' + state.chatConversationId).then(function (response) {
            meetingMessagesEl.innerHTML = '';
            response.data.data.forEach(appendChatMessage);
        }).catch(function () {});
    }

    function pollChatMessages() {
        axios.get('/api/v1/messages/' + state.chatConversationId, { params: { after: state.lastChatMessageId } }).then(function (response) {
            response.data.data.forEach(appendChatMessage);
        }).catch(function () {});
    }

    meetingChatForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var body = meetingChatInput.value.trim();
        if (!body || !state.chatConversationId) return;

        axios.post('/api/v1/messages', { conversation_id: state.chatConversationId, body: body }).then(function (response) {
            appendChatMessage(response.data.data);
            meetingChatInput.value = '';
        }).catch(function () {});
    });

    // ---- Whiteboard ----
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

    // Pointer Events (not mouse-only) - a real touch drag on a <canvas>
    // never synthesizes mousemove the way a simple tap does, so the
    // whiteboard was previously completely inert to touch input on
    // mobile. Pointer Events unify mouse/touch/pen into one model this
    // canvas already has `touch-none` (touch-action: none) applied to,
    // so the browser never hijacks the gesture to scroll/zoom the page
    // instead of drawing.
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
    // A call/notification or the OS itself can interrupt a touch
    // mid-stroke - discard rather than post a corrupt/partial shape.
    wbCanvas.addEventListener('pointercancel', function () {
        state.wbDrawing = false;
        state.wbStrokePoints = [];
        wbDrawStart = null;
    });

    function postWbObject(tool, data, color, thickness) {
        axios.post('/api/v1/whiteboards/' + state.whiteboard.id + '/pages/' + state.wbPageId + '/objects', {
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
        axios.get('/api/v1/whiteboards/' + state.whiteboard.id + '/pages/' + state.wbPageId + '/objects', {
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
        axios.post('/api/v1/whiteboards/' + state.whiteboard.id + '/pages/' + state.wbPageId + '/clear').then(function () {
            wbObjects = {};
            wbCtx.clearRect(0, 0, wbCanvas.width, wbCanvas.height);
        }).catch(function () {});
    });

    document.getElementById('wb-undo-btn').addEventListener('click', function () {
        axios.post('/api/v1/whiteboards/' + state.whiteboard.id + '/undo').catch(function () {});
    });

    document.getElementById('wb-redo-btn').addEventListener('click', function () {
        axios.post('/api/v1/whiteboards/' + state.whiteboard.id + '/redo').catch(function () {});
    });
});
</script>
