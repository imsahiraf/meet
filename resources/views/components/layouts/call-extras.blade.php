        // ---- Call extras (owner 2026-11-08: the call features people know from the big call apps) -------------
        // Included inside the call script of app.blade.php, so it shares state, timers, peers and the tile helpers.
        // Background blur, noise reduction, captions, polls and questions, pin / speaker view, low data mode,
        // call waiting and switching a voice call to video. Small in-call messages ride the 'event' signal
        // (POST /calls/{id}/event, fanned out to everyone else in the call).
        var MEDIAPIPE_BASE = @json(asset('vendor/mediapipe'));
        var extras = freshExtras();

        function freshExtras() {
            return {
                blur: null, noiseOn: true, captionsOn: false, recog: null, lowData: false, peerLow: {}, capApplied: {},
                viewMode: (extras && extras.viewMode) || 'grid', pinned: null, speaker: null, speakerLoop: null,
                polls: {}, pollOrder: [], questions: {}, questionOrder: [], unseen: 0, sheetOpen: false, tab: 'polls',
                waitingCall: null, skipEnded: false, toastTimer: null, captionTimer: null,
            };
        }

        var moreVideoBtn = document.getElementById('call-more-video-btn');
        var moreBlurBtn = document.getElementById('call-more-blur-btn');
        var moreNoiseBtn = document.getElementById('call-more-noise-btn');
        var moreCaptionsBtn = document.getElementById('call-more-captions-btn');
        var morePollsBtn = document.getElementById('call-more-polls-btn');
        var moreLayoutBtn = document.getElementById('call-more-layout-btn');
        var moreLowDataBtn = document.getElementById('call-more-lowdata-btn');
        var captionEl = document.getElementById('call-caption');
        var toastEl = document.getElementById('call-toast');
        var pollsSheet = document.getElementById('call-polls-sheet');
        var waitingEl = document.getElementById('call-waiting');

        function sendCallEvent(kind, data) {
            if (!state || !state.id) return;
            axios.post('/api/v1/calls/' + state.id + '/event', { kind: kind, data: data || {} }).catch(function () {});
        }

        function nameOf(userId) {
            if (String(userId) === String(myUserId)) return 'You';
            return (state && state.knownNames && state.knownNames[String(userId)]) || 'Someone';
        }

        function newId() {
            return Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
        }

        function callToast(text, actionLabel, action, ms) {
            document.getElementById('call-toast-text').textContent = text;
            var btn = document.getElementById('call-toast-action');
            btn.classList.toggle('hidden', !actionLabel);
            btn.textContent = actionLabel || '';
            btn.onclick = function () { hideToast(); if (action) action(); };
            toastEl.classList.remove('hidden');
            toastEl.classList.add('flex');
            clearTimeout(extras.toastTimer);
            extras.toastTimer = setTimeout(hideToast, ms || 5000);
        }

        function hideToast() {
            toastEl.classList.add('hidden');
            toastEl.classList.remove('flex');
        }

        function setToggle(btn, label, on) {
            btn.textContent = label + ': ' + (on ? 'On' : 'Off');
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        }

        function refreshExtrasMenu() {
            if (!state) return;
            var isVideo = state.type === 'video';
            moreVideoBtn.classList.toggle('hidden', isVideo);
            moreBlurBtn.classList.toggle('hidden', !isVideo);
            setToggle(moreBlurBtn, 'Background blur', !!extras.blur);
            setToggle(moreNoiseBtn, 'Noise reduction', extras.noiseOn);
            setToggle(moreCaptionsBtn, 'Captions', extras.captionsOn);
            setToggle(moreLowDataBtn, 'Low data mode', extras.lowData);
            moreLayoutBtn.classList.toggle('hidden', grid.children.length < 3);
            moreLayoutBtn.textContent = extras.viewMode === 'speaker' ? 'Layout: speaker (tap for grid)' : 'Layout: grid (tap for speaker)';
            var badge = document.getElementById('call-polls-new');
            badge.textContent = String(extras.unseen);
            badge.classList.toggle('hidden', !extras.unseen);
        }

        moreBtn.addEventListener('click', refreshExtrasMenu);

        function extrasOnActive() {
            extras = freshExtras();
            var audio = state.localStream && state.localStream.getAudioTracks()[0];
            if (audio && audio.getSettings) extras.noiseOn = audio.getSettings().noiseSuppression !== false;
            renderPolls();
            renderQuestions();
            clearInterval(extras.speakerLoop);
            extras.speakerLoop = setInterval(speakerTick, 700);
            timers.waitingPoll = setInterval(checkWaitingCall, Math.max(2000, cfg.incoming_call_poll_ms || 2000));
            refreshExtrasMenu();
        }

        function extrasOnTeardown() {
            stopBlur();
            stopCaptions();
            clearInterval(extras.speakerLoop);
            hideWaiting();
            hideToast();
            closePollsSheet();
            captionEl.classList.add('hidden');
        }

        // ---- Events from the others --------------------------------------------------------

        function handleCallEvent(peerId, event) {
            if (!event || !state) return;
            var data = event.data || {};
            var key = String(peerId);
            if (event.kind === 'caption' && typeof data.text === 'string') {
                showCaption(nameOf(peerId), data.text);
            } else if (event.kind === 'poll') {
                addPoll(data, peerId);
            } else if (event.kind === 'vote' && extras.polls[data.poll] && typeof data.choice === 'number') {
                extras.polls[data.poll].votes[key] = data.choice;
                renderPolls();
            } else if (event.kind === 'question') {
                addQuestion(data, peerId);
            } else if (event.kind === 'upvote' && extras.questions[data.question]) {
                extras.questions[data.question].votes[key] = true;
                renderQuestions();
            } else if (event.kind === 'video') {
                setTileVideoVisible(peerId, !!data.on);
                if (data.on && state.type !== 'video') {
                    callToast(nameOf(peerId) + ' turned on video.', 'Turn on mine', switchToVideo, 8000);
                }
            } else if (event.kind === 'low-data') {
                extras.peerLow[key] = !!data.on;
            }
        }

        // ---- Voice call to video ------------------------------------------------------------

        function switchToVideo() {
            if (!state || !state.joined || state.type === 'video' || !state.localStream) return;
            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false }).then(function (stream) {
                if (!state) { stream.getTracks().forEach(function (t) { t.stop(); }); return; }
                var track = stream.getVideoTracks()[0];
                state.localStream.addTrack(track);
                if (!state.isScreenSharing) {
                    Object.keys(state.peers).forEach(function (k) {
                        var peer = state.peers[k];
                        if (peer.videoSender) peer.videoSender.replaceTrack(track).catch(function () {});
                    });
                }
                state.type = 'video';
                currentFacingMode = 'user';
                cameraBtn.classList.remove('hidden');
                setCameraBtnUi(true);
                attachTileStream(myUserId, state.localStream);
                setTileVideoVisible(myUserId, true);
                updateFlipCameraAvailability();
                axios.patch('/api/v1/calls/' + state.id + '/media', { video_enabled: true }).catch(function () {});
                sendCallEvent('video', { on: true });
                refreshExtrasMenu();
            }).catch(function () { showCallError('Could not turn on the camera.'); });
        }

        moreVideoBtn.addEventListener('click', function () { closeMoreMenu(); switchToVideo(); });

        // ---- Background blur ----------------------------------------------------------------
        // The browser's own blur when the device has one (some laptops), otherwise MediaPipe's selfie
        // segmenter (shipped with Meet, loaded only when blur is switched on) separates you from the room.

        var segmenterPromise = null;

        function loadSegmenter() {
            if (segmenterPromise) return segmenterPromise;
            segmenterPromise = import(MEDIAPIPE_BASE + '/vision_bundle.mjs').then(function (vision) {
                return vision.FilesetResolver.forVisionTasks(MEDIAPIPE_BASE + '/wasm').then(function (files) {
                    var make = function (delegate) {
                        return vision.ImageSegmenter.createFromOptions(files, {
                            baseOptions: { modelAssetPath: MEDIAPIPE_BASE + '/selfie_segmenter.tflite', delegate: delegate },
                            runningMode: 'VIDEO', outputCategoryMask: false, outputConfidenceMasks: true,
                        });
                    };
                    return make('GPU').catch(function () { return make('CPU'); });
                });
            });
            segmenterPromise.catch(function () { segmenterPromise = null; });
            return segmenterPromise;
        }

        function useOutgoingVideo(track) {
            var old = state.localStream.getVideoTracks()[0];
            if (old) state.localStream.removeTrack(old);
            state.localStream.addTrack(track);
            if (!state.isScreenSharing) {
                Object.keys(state.peers).forEach(function (k) {
                    var peer = state.peers[k];
                    if (peer.videoSender) peer.videoSender.replaceTrack(track).catch(function () {});
                });
            }
            attachTileStream(myUserId, state.localStream);
        }

        function startBlur() {
            if (!state || !state.localStream || state.type !== 'video' || extras.blur) return;
            var raw = state.localStream.getVideoTracks()[0];
            if (!raw) return;
            var caps = raw.getCapabilities ? raw.getCapabilities() : {};
            if (Array.isArray(caps.backgroundBlur) && caps.backgroundBlur.indexOf(true) !== -1) {
                raw.applyConstraints({ backgroundBlur: true }).then(function () {
                    extras.blur = { native: true, raw: raw };
                    refreshExtrasMenu();
                }).catch(function () { startBlurPipeline(raw); });
                return;
            }
            startBlurPipeline(raw);
        }

        function startBlurPipeline(raw) {
            callToast('Turning on background blur...', null, null, 3000);
            var pending = { pending: true, raw: raw };
            extras.blur = pending;
            loadSegmenter().then(function (segmenter) {
                if (extras.blur !== pending || !state) return;
                var video = document.createElement('video');
                video.muted = true;
                video.playsInline = true;
                video.srcObject = new MediaStream([raw]);
                video.play().catch(function () {});
                var out = document.createElement('canvas');
                var ctx = out.getContext('2d');
                var person = document.createElement('canvas');
                var pctx = person.getContext('2d');
                var small = document.createElement('canvas');
                var sctx = small.getContext('2d');
                var mask = document.createElement('canvas');
                var mctx = mask.getContext('2d');
                var maskImage = null;
                var lastTs = 0;
                var job = { raw: raw, running: true, track: null, timer: null };

                function sizeTo(w, h) {
                    out.width = person.width = w;
                    out.height = person.height = h;
                    small.width = Math.max(1, Math.round(w / 12));
                    small.height = Math.max(1, Math.round(h / 12));
                }
                sizeTo(raw.getSettings().width || 640, raw.getSettings().height || 480);

                function frame() {
                    if (!job.running) return;
                    if (video.readyState >= 2 && video.videoWidth) {
                        if (out.width !== video.videoWidth || out.height !== video.videoHeight) sizeTo(video.videoWidth, video.videoHeight);
                        var ts = Math.max(performance.now(), lastTs + 1);
                        lastTs = ts;
                        try {
                            var result = segmenter.segmentForVideo(video, ts);
                            var masks = result.confidenceMasks || [];
                            var m = masks[masks.length > 1 ? 1 : 0];
                            if (m) {
                                var values = m.getAsFloat32Array();
                                if (!maskImage || maskImage.width !== m.width || maskImage.height !== m.height) {
                                    mask.width = m.width;
                                    mask.height = m.height;
                                    maskImage = mctx.createImageData(m.width, m.height);
                                }
                                var px = maskImage.data;
                                for (var i = 0; i < values.length; i++) px[i * 4 + 3] = values[i] * 255;
                                mctx.putImageData(maskImage, 0, 0);
                            }
                            if (result.close) result.close();
                            // the room: shrink and stretch the picture (a soft blur that works in every browser)
                            sctx.drawImage(video, 0, 0, small.width, small.height);
                            ctx.imageSmoothingEnabled = true;
                            ctx.globalCompositeOperation = 'copy';
                            ctx.drawImage(small, 0, 0, out.width, out.height);
                            // you: the sharp picture cut out by the mask
                            pctx.globalCompositeOperation = 'copy';
                            pctx.drawImage(video, 0, 0, person.width, person.height);
                            pctx.globalCompositeOperation = 'destination-in';
                            pctx.drawImage(mask, 0, 0, person.width, person.height);
                            ctx.globalCompositeOperation = 'source-over';
                            ctx.drawImage(person, 0, 0);
                        } catch (e) { /* skip this frame */ }
                    }
                    job.timer = video.requestVideoFrameCallback ? video.requestVideoFrameCallback(frame) : setTimeout(frame, 40);
                }

                job.stop = function () {
                    job.running = false;
                    if (video.cancelVideoFrameCallback && job.timer) video.cancelVideoFrameCallback(job.timer);
                    clearTimeout(job.timer);
                    video.srcObject = null;
                };
                frame();
                job.track = out.captureStream(24).getVideoTracks()[0];
                job.track.enabled = raw.enabled;
                extras.blur = job;
                useOutgoingVideo(job.track);
                hideToast();
                refreshExtrasMenu();
            }).catch(function () {
                if (extras.blur === pending) extras.blur = null;
                callToast('Background blur does not work on this device.', null, null, 5000);
                refreshExtrasMenu();
            });
        }

        // Back to the plain camera (also before a camera flip and when the call ends).
        function stopBlur() {
            var job = extras && extras.blur;
            if (!job) return;
            extras.blur = null;
            if (job.native) {
                job.raw.applyConstraints({ backgroundBlur: false }).catch(function () {});
                return;
            }
            if (job.pending) return;
            job.stop();
            if (state && state.localStream) {
                job.raw.enabled = job.track.enabled;
                useOutgoingVideo(job.raw);
            } else {
                job.raw.stop();
            }
            job.track.stop();
        }

        moreBlurBtn.addEventListener('click', function () {
            closeMoreMenu();
            if (extras.blur) stopBlur(); else startBlur();
            refreshExtrasMenu();
        });

        // ---- Noise reduction ----------------------------------------------------------------
        // On by default. Off is for music or a quiet room where every sound should come through.

        moreNoiseBtn.addEventListener('click', function () {
            closeMoreMenu();
            var audio = state && state.localStream && state.localStream.getAudioTracks()[0];
            if (!audio) return;
            var on = !extras.noiseOn;
            audio.applyConstraints({ noiseSuppression: on, echoCancellation: true, autoGainControl: on }).then(function () {
                extras.noiseOn = on;
                callToast(on ? 'Noise reduction on: background sounds are filtered out.' : 'Noise reduction off: every sound comes through (good for music).');
                refreshExtrasMenu();
            }).catch(function () { callToast('This device cannot change noise reduction.'); });
        });

        // ---- Captions ----------------------------------------------------------------------
        // Your browser turns your own speech into text; the text goes to everyone in the call.

        var SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;

        function showCaption(who, text) {
            captionEl.textContent = who + ': ' + text;
            captionEl.classList.remove('hidden');
            clearTimeout(extras.captionTimer);
            extras.captionTimer = setTimeout(function () { captionEl.classList.add('hidden'); }, 6000);
        }

        function startCaptions() {
            if (!SpeechRec) {
                callToast('This browser cannot make captions of your voice (use Chrome or Edge). You still see the captions of others.', null, null, 7000);
                return;
            }
            var recog = new SpeechRec();
            recog.continuous = true;
            recog.interimResults = false;
            recog.lang = document.documentElement.lang && document.documentElement.lang.length > 2 ? document.documentElement.lang : (navigator.language || 'en-IN');
            recog.onresult = function (event) {
                for (var i = event.resultIndex; i < event.results.length; i++) {
                    if (!event.results[i].isFinal) continue;
                    var text = event.results[i][0].transcript.trim().slice(0, 500);
                    if (!text) continue;
                    showCaption('You', text);
                    sendCallEvent('caption', { text: text });
                }
            };
            recog.onend = function () {
                if (extras.recog === recog && extras.captionsOn) {
                    try { recog.start(); } catch (e) { /* already running */ }
                }
            };
            recog.onerror = function (event) {
                if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                    stopCaptions();
                    callToast('Captions need the microphone and the speech service of the browser.');
                }
            };
            extras.recog = recog;
            extras.captionsOn = true;
            try { recog.start(); } catch (e) { /* started */ }
            callToast('Captions on: what you say is shown as text to everyone in the call.');
        }

        function stopCaptions() {
            if (!extras) return;
            extras.captionsOn = false;
            if (extras.recog) {
                extras.recog.onend = null;
                try { extras.recog.stop(); } catch (e) { /* stopped */ }
                extras.recog = null;
            }
        }

        moreCaptionsBtn.addEventListener('click', function () {
            closeMoreMenu();
            if (extras.captionsOn) stopCaptions(); else startCaptions();
            refreshExtrasMenu();
        });

        // ---- Pin, speaker view and the person talking ---------------------------------------

        function extrasSpotlightUserId() {
            if (extras.pinned != null && document.getElementById('call-tile-' + extras.pinned)) return extras.pinned;
            if (extras.viewMode === 'speaker' && grid.children.length > 2 && extras.speaker != null
                && document.getElementById('call-tile-' + extras.speaker)) return extras.speaker;
            return null;
        }

        function togglePin(key) {
            extras.pinned = extras.pinned === key ? null : key;
            [].forEach.call(grid.querySelectorAll('.call-tile'), function (tile) {
                tile.classList.toggle('call-tile--pinned', tile.dataset.tileUserId === extras.pinned);
            });
            updateGridLayout();
        }

        moreLayoutBtn.addEventListener('click', function () {
            closeMoreMenu();
            extras.viewMode = extras.viewMode === 'speaker' ? 'grid' : 'speaker';
            updateGridLayout();
            refreshExtrasMenu();
        });

        // Every 0.7 s: who is talking (the loudest incoming voice) and the data limits of each connection.
        function speakerTick() {
            if (!state || !state.joined) return;
            var loudest = null;
            var loudestLevel = 0.04;
            Object.keys(state.peers).forEach(function (k) {
                var peer = state.peers[k];
                if (!peer.pc) return;
                peer.pc.getReceivers().forEach(function (receiver) {
                    if (!receiver.track || receiver.track.kind !== 'audio' || !receiver.getSynchronizationSources) return;
                    receiver.getSynchronizationSources().forEach(function (source) {
                        if (source.audioLevel > loudestLevel) { loudestLevel = source.audioLevel; loudest = k; }
                    });
                });
                applySenderLimit(k);
            });
            [].forEach.call(grid.querySelectorAll('.call-tile'), function (tile) {
                tile.classList.toggle('call-tile--talking', tile.dataset.tileUserId === loudest);
            });
            if (loudest != null && loudest !== extras.speaker) {
                extras.speaker = loudest;
                if (extras.viewMode === 'speaker' && extras.pinned == null) updateGridLayout();
            }
        }

        // ---- Low data mode ------------------------------------------------------------------
        // Your video goes out small and slow, and the others are asked to send you less.

        function applySenderLimit(key) {
            var peer = state.peers[key];
            var low = extras.lowData || !!extras.peerLow[key];
            if (!peer || !peer.videoSender || !peer.videoSender.getParameters || extras.capApplied[key] === low) return;
            if (!peer.pc || peer.pc.connectionState !== 'connected') return;
            var params = peer.videoSender.getParameters();
            if (!params.encodings || !params.encodings.length) return;
            var enc = params.encodings[0];
            if (low) {
                enc.maxBitrate = 150000;
                enc.maxFramerate = 12;
                enc.scaleResolutionDownBy = 2;
            } else {
                delete enc.maxBitrate;
                delete enc.maxFramerate;
                enc.scaleResolutionDownBy = 1;
            }
            extras.capApplied[key] = low;
            peer.videoSender.setParameters(params).catch(function () { delete extras.capApplied[key]; });
        }

        moreLowDataBtn.addEventListener('click', function () {
            closeMoreMenu();
            extras.lowData = !extras.lowData;
            sendCallEvent('low-data', { on: extras.lowData });
            Object.keys(state.peers).forEach(applySenderLimit);
            callToast(extras.lowData ? 'Low data mode on: video is sent and received smaller.' : 'Low data mode off.');
            refreshExtrasMenu();
        });

        // ---- Polls and questions ------------------------------------------------------------

        function el(tag, className, text) {
            var node = document.createElement(tag);
            if (className) node.className = className;
            if (text != null) node.textContent = text;
            return node;
        }

        function noteUnseen(tab) {
            if (extras.sheetOpen && extras.tab === tab) return;
            extras.unseen += 1;
            refreshExtrasMenu();
        }

        function addPoll(data, byUserId) {
            if (!data || typeof data.id !== 'string' || extras.polls[data.id] || typeof data.q !== 'string' || !Array.isArray(data.options)) return;
            var options = data.options.filter(function (o) { return typeof o === 'string' && o.trim(); }).slice(0, 8);
            if (options.length < 2) return;
            extras.polls[data.id] = { id: data.id, q: data.q.slice(0, 200), options: options, by: String(byUserId), votes: {} };
            extras.pollOrder.unshift(data.id);
            renderPolls();
            if (String(byUserId) !== String(myUserId)) {
                noteUnseen('polls');
                callToast(nameOf(byUserId) + ' started a poll: ' + data.q.slice(0, 60), 'Vote', function () { openPollsSheet('polls'); }, 8000);
            }
        }

        function renderPolls() {
            var list = document.getElementById('call-polls-list');
            list.innerHTML = '';
            if (!extras.pollOrder.length) {
                list.appendChild(el('p', 'text-sm text-gray-500', 'No polls yet. Ask everyone in the call something below.'));
                return;
            }
            extras.pollOrder.forEach(function (id) {
                var poll = extras.polls[id];
                var mine = poll.votes[String(myUserId)];
                var total = Object.keys(poll.votes).length;
                var card = el('div', 'rounded-2xl border border-gray-200 p-3');
                card.appendChild(el('p', 'text-sm font-semibold', poll.q));
                card.appendChild(el('p', 'mb-2 text-xs text-gray-500', 'by ' + nameOf(poll.by) + ' - ' + total + (total === 1 ? ' vote' : ' votes')));
                poll.options.forEach(function (option, index) {
                    var count = Object.keys(poll.votes).filter(function (u) { return poll.votes[u] === index; }).length;
                    var pct = total ? Math.round(count * 100 / total) : 0;
                    var btn = el('button', 'call-poll-option' + (mine === index ? ' is-mine' : ''));
                    btn.type = 'button';
                    btn.setAttribute('aria-pressed', mine === index ? 'true' : 'false');
                    var bar = el('span', 'call-poll-bar');
                    bar.style.width = pct + '%';
                    btn.appendChild(bar);
                    btn.appendChild(el('span', 'call-poll-text', option));
                    btn.appendChild(el('span', 'call-poll-count', count + ' (' + pct + '%)'));
                    btn.addEventListener('click', function () {
                        poll.votes[String(myUserId)] = index;
                        sendCallEvent('vote', { poll: poll.id, choice: index });
                        renderPolls();
                    });
                    card.appendChild(btn);
                });
                list.appendChild(card);
            });
        }

        function addOptionInput(value) {
            var wrap = document.getElementById('call-poll-options');
            if (wrap.children.length >= 8) return;
            var input = el('input', 'w-full rounded-xl border border-gray-300 px-3 py-2 text-sm');
            input.type = 'text';
            input.maxLength = 80;
            input.placeholder = 'Choice ' + (wrap.children.length + 1);
            input.setAttribute('aria-label', input.placeholder);
            input.value = value || '';
            wrap.appendChild(input);
        }

        function resetPollForm() {
            document.getElementById('call-poll-q').value = '';
            document.getElementById('call-poll-options').innerHTML = '';
            addOptionInput();
            addOptionInput();
        }
        resetPollForm();

        document.getElementById('call-poll-add-option').addEventListener('click', function () { addOptionInput(); });

        document.getElementById('call-poll-form').addEventListener('submit', function (event) {
            event.preventDefault();
            var q = document.getElementById('call-poll-q').value.trim();
            var options = [].map.call(document.getElementById('call-poll-options').querySelectorAll('input'), function (i) { return i.value.trim(); })
                .filter(Boolean);
            if (!q || options.length < 2) {
                callToast('Write the question and at least two choices.');
                return;
            }
            var poll = { id: newId(), q: q, options: options };
            addPoll(poll, myUserId);
            sendCallEvent('poll', poll);
            resetPollForm();
        });

        function addQuestion(data, byUserId) {
            if (!data || typeof data.id !== 'string' || extras.questions[data.id] || typeof data.text !== 'string' || !data.text.trim()) return;
            extras.questions[data.id] = { id: data.id, text: data.text.slice(0, 300), by: String(byUserId), votes: {}, at: Date.now() };
            extras.questionOrder.push(data.id);
            renderQuestions();
            if (String(byUserId) !== String(myUserId)) noteUnseen('questions');
        }

        function renderQuestions() {
            var list = document.getElementById('call-questions-list');
            list.innerHTML = '';
            var ids = extras.questionOrder.slice().sort(function (a, b) {
                var qa = extras.questions[a], qb = extras.questions[b];
                return Object.keys(qb.votes).length - Object.keys(qa.votes).length || qa.at - qb.at;
            });
            if (!ids.length) {
                list.appendChild(el('p', 'text-sm text-gray-500', 'No questions yet. The most liked question stays on top.'));
                return;
            }
            ids.forEach(function (id) {
                var question = extras.questions[id];
                var votes = Object.keys(question.votes).length;
                var liked = !!question.votes[String(myUserId)];
                var row = el('div', 'flex items-start gap-2 rounded-2xl border border-gray-200 p-3');
                var body = el('div', 'min-w-0 flex-1');
                body.appendChild(el('p', 'break-words text-sm', question.text));
                body.appendChild(el('p', 'text-xs text-gray-500', nameOf(question.by)));
                row.appendChild(body);
                var up = el('button', 'shrink-0 rounded-full border px-2.5 py-1 text-xs font-semibold ' + (liked ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 text-gray-700'), '▲ ' + votes);
                up.type = 'button';
                up.setAttribute('aria-label', 'Like this question (' + votes + ')');
                up.disabled = liked;
                up.addEventListener('click', function () {
                    question.votes[String(myUserId)] = true;
                    sendCallEvent('upvote', { question: question.id });
                    renderQuestions();
                });
                row.appendChild(up);
                list.appendChild(row);
            });
        }

        document.getElementById('call-question-form').addEventListener('submit', function (event) {
            event.preventDefault();
            var input = document.getElementById('call-question-input');
            var text = input.value.trim();
            if (!text) return;
            var question = { id: newId(), text: text };
            addQuestion(question, myUserId);
            sendCallEvent('question', question);
            input.value = '';
        });

        function showPollsTab(tab) {
            extras.tab = tab;
            document.getElementById('call-polls-pane').classList.toggle('hidden', tab !== 'polls');
            document.getElementById('call-questions-pane').classList.toggle('hidden', tab !== 'questions');
            [].forEach.call(document.querySelectorAll('.call-polls-tab'), function (btn) {
                btn.classList.toggle('is-active', btn.dataset.pollsTab === tab);
            });
        }

        function openPollsSheet(tab) {
            closeMoreMenu();
            showPollsTab(tab || extras.tab);
            extras.sheetOpen = true;
            extras.unseen = 0;
            pollsSheet.classList.remove('hidden');
            pollsSheet.classList.add('flex');
            refreshExtrasMenu();
        }

        function closePollsSheet() {
            extras.sheetOpen = false;
            pollsSheet.classList.add('hidden');
            pollsSheet.classList.remove('flex');
        }

        [].forEach.call(document.querySelectorAll('.call-polls-tab'), function (btn) {
            btn.addEventListener('click', function () { showPollsTab(btn.dataset.pollsTab); });
        });
        morePollsBtn.addEventListener('click', function () { openPollsSheet(); });
        document.getElementById('call-polls-close').addEventListener('click', closePollsSheet);

        // ---- Call waiting -------------------------------------------------------------------
        // A second call while you talk: decline it, or end this call and answer.

        function checkWaitingCall() {
            if (!state || !state.joined || IN_CALL_FRAME) return;
            axios.get('/api/v1/calls/incoming').then(function (response) {
                var call = response.data.data;
                if (state && call && call.call_status === 'ringing' && call.id !== state.id) {
                    showWaiting(call);
                } else {
                    hideWaiting();
                }
            }).catch(function () {});
        }

        function beep() {
            if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
            if (!audioCtx) return;
            try {
                var osc = audioCtx.createOscillator();
                var gain = audioCtx.createGain();
                osc.frequency.value = 660;
                gain.gain.value = 0.08;
                osc.connect(gain).connect(audioCtx.destination);
                osc.start();
                osc.stop(audioCtx.currentTime + 0.25);
            } catch (e) { /* no sound */ }
        }

        function showWaiting(call) {
            var isNew = !extras.waitingCall || extras.waitingCall.id !== call.id;
            extras.waitingCall = call;
            if (!isNew) return;
            document.getElementById('call-waiting-name').textContent = (call.caller && call.caller.full_name) || 'Someone';
            document.getElementById('call-waiting-type').textContent = 'is calling you (' + (call.call_type === 'video' ? 'video' : 'voice') + ')';
            setAvatar(document.getElementById('call-waiting-avatar'), call.caller && call.caller.full_name, call.caller && call.caller.profile_photo);
            waitingEl.classList.remove('hidden');
            waitingEl.classList.add('flex');
            document.getElementById('call-active').classList.remove('call-ui-hidden');
            beep();
        }

        function hideWaiting() {
            if (!extras) return;
            extras.waitingCall = null;
            waitingEl.classList.add('hidden');
            waitingEl.classList.remove('flex');
        }

        document.getElementById('call-waiting-decline').addEventListener('click', function () {
            var call = extras.waitingCall;
            hideWaiting();
            if (call) axios.post('/api/v1/calls/' + call.id + '/reject').catch(function () {});
        });

        document.getElementById('call-waiting-answer').addEventListener('click', function () {
            var call = extras.waitingCall;
            if (!call || !state) return;
            var oldId = state.id;
            extras.skipEnded = true;
            axios.post('/api/v1/calls/' + oldId + '/end').catch(function () {});
            teardown();
            openIncoming(call);
            acceptIncomingCall();
        });
