{{--
    Live captions, recording, breakout rooms and meeting notes (owner 2026-11-06). Separate from room-script so the call code
    stays as it is: this only reads the room's <video> elements and talks to /api/v1/meetings/{id}/events | captions | recording |
    recordings | breakouts | summary.
--}}
<div id="mx-caption" aria-live="polite" style="position:fixed;left:50%;transform:translateX(-50%);bottom:96px;z-index:30;max-width:min(92vw,760px);display:none;
    background:rgba(0,0,0,.72);color:#fff;border-radius:10px;padding:8px 14px;font-size:15px;line-height:1.4;text-align:center;pointer-events:none"></div>
<div id="mx-rec" style="position:fixed;top:10px;left:50%;transform:translateX(-50%);z-index:31;display:none;background:#dc2626;color:#fff;border-radius:999px;padding:4px 12px;font-size:13px;font-weight:600">
    &#9679; Recording</div>
<div id="mx-toast" role="status" style="position:fixed;top:48px;left:50%;transform:translateX(-50%);z-index:32;display:none;background:#111827;color:#fff;border-radius:10px;padding:10px 16px;font-size:14px;max-width:92vw"></div>
<div id="mx-menu" role="menu" style="position:fixed;z-index:33;display:none;background:#fff;color:#111827;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.25);padding:6px;min-width:220px">
    <button type="button" role="menuitem" data-mx="record" class="mx-item">Start recording</button>
    <button type="button" role="menuitem" data-mx="rooms" class="mx-item">Breakout rooms</button>
    <button type="button" role="menuitem" data-mx="notes" class="mx-item">Meeting notes (summary)</button>
    <button type="button" role="menuitem" data-mx="recordings" class="mx-item">Recordings</button>
</div>
<div id="mx-dialog" role="dialog" aria-modal="true" style="position:fixed;inset:0;z-index:34;display:none;background:rgba(0,0,0,.45);align-items:center;justify-content:center;padding:16px">
    <div style="background:#fff;color:#111827;border-radius:14px;max-width:560px;width:100%;max-height:85vh;overflow:auto;padding:18px">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px"><h2 id="mx-dlg-title" style="margin:0;font-size:18px;font-weight:700"></h2>
            <button type="button" id="mx-dlg-close" style="font-size:22px;line-height:1;padding:4px 8px" aria-label="Close">&times;</button></div>
        <div id="mx-dlg-body" style="margin-top:12px;font-size:14px"></div>
    </div>
</div>
<style>
    .mx-item{display:block;width:100%;text-align:left;padding:9px 12px;border-radius:8px;font-size:14px}
    .mx-item:hover{background:#f3f4f6}
    .mx-btn{display:inline-flex;align-items:center;gap:6px;border-radius:8px;padding:8px 12px;font-size:14px;font-weight:600;background:#4f46e5;color:#fff}
    .mx-btn.line{background:#fff;color:#111827;border:1px solid #d1d5db}
    #mx-dlg-body pre{white-space:pre-wrap;overflow-wrap:anywhere;font-family:inherit;margin:0}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var app = document.getElementById('meeting-app');
    if (!app || !window.axios) return;
    var guest = !app.dataset.meetingId && !!app.dataset.token; // guest page: captions only (no account, no host tools)
    var base = guest ? '/api/v1/meetings/guest/' + app.dataset.token : '/api/v1/meetings/' + app.dataset.meetingId;
    var after = 0, me = null, role = 'participant', started = false, cc = false, recog = null;
    var $ = function (id) { return document.getElementById(id); };
    var toast = function (text, ms) { var t = $('mx-toast'); t.textContent = text; t.style.display = 'block'; clearTimeout(t._h); t._h = setTimeout(function () { t.style.display = 'none'; }, ms || 4000); };
    var host = function () { return role === 'host' || role === 'co_host'; };
    var msg = function (e) { return (e && e.response && e.response.data && e.response.data.message) || 'Something went wrong. Please try again.'; };

    // ---------- event stream (starts once the room is open) ----------
    var captionTimer = null;
    function showCaption(name, text) {
        if (!cc) return;
        var c = $('mx-caption'); c.textContent = name + ': ' + text; c.style.display = 'block';
        clearTimeout(captionTimer); captionTimer = setTimeout(function () { c.style.display = 'none'; }, 5000);
    }
    function poll() {
        axios.get(base + '/events', { params: { after: after } }).then(function (r) {
            var d = r.data.data; me = d.me;
            $('mx-rec').style.display = d.recording ? 'block' : 'none';
            d.events.forEach(function (e) {
                after = Math.max(after, e.id);
                if (e.type === 'caption' && e.from !== me) showCaption(e.payload.name, e.payload.text);
                if (e.type === 'recording') toast(e.payload.on ? (e.payload.by + ' started recording this meeting.') : 'Recording stopped.');
                if (e.type === 'breakout' && e.payload.assign && e.payload.assign[me]) {
                    var room = (e.payload.rooms || []).find(function (x) { return x.id === e.payload.assign[me]; });
                    toast('Moving you to breakout room ' + (room ? room.name : '') + ' ...', 3000);
                    setTimeout(function () { go(e.payload.assign[me]); }, 2500);
                }
                if (e.type === 'return') { toast('The host called everyone back to the main room ...', 3000); setTimeout(function () { go(e.payload.meeting_id); }, 2500); }
            });
        }).catch(function () {});
    }
    function go(meetingId) {
        axios.post(base + '/leave').catch(function () {}).finally(function () { window.location.href = '/meetings/' + meetingId; });
    }
    function loadRole() {
        if (guest) return;
        axios.get(base + '/participants').then(function (r) {
            var mine = (r.data.data || []).find(function (p) { return p.id === me; });
            if (mine) role = mine.role;
        }).catch(function () {});
    }
    function start() {
        if (started) return; started = true;
        poll(); setInterval(poll, 1500);
        setTimeout(loadRole, 1600); setInterval(loadRole, 15000);
    }
    new MutationObserver(function () { if (!$('room-panel').classList.contains('hidden')) start(); }).observe($('room-panel'), { attributes: true, attributeFilter: ['class'] });
    if (!$('room-panel').classList.contains('hidden')) start();

    // ---------- live captions: this browser turns your own speech into text ----------
    var Rec = window.SpeechRecognition || window.webkitSpeechRecognition;
    $('mx-cc-btn').addEventListener('click', function () {
        cc = !cc;
        this.setAttribute('aria-pressed', cc ? 'true' : 'false');
        this.style.background = cc ? 'rgba(79,70,229,.85)' : '';
        if (!cc) { if (recog) { recog.onend = null; recog.stop(); recog = null; } $('mx-caption').style.display = 'none'; return; }
        if (!Rec) { toast('This browser cannot make captions of your voice (use Chrome or Edge). You still see the captions of others.', 6000); return; }
        recog = new Rec();
        recog.continuous = true; recog.interimResults = false; recog.lang = document.documentElement.lang === 'hi' ? 'hi-IN' : (navigator.language || 'en-IN');
        recog.onresult = function (ev) {
            for (var i = ev.resultIndex; i < ev.results.length; i++) {
                if (!ev.results[i].isFinal) continue;
                var text = ev.results[i][0].transcript.trim();
                if (text) { showCaption('You', text); axios.post(base + '/captions', { text: text }).catch(function () {}); }
            }
        };
        recog.onend = function () { if (cc && recog) { try { recog.start(); } catch (e) {} } }; // keeps listening after pauses
        try { recog.start(); } catch (e) {}
        toast('Captions on. Everyone with captions on sees what is said; the text is kept for the meeting notes.', 5000);
    });

    // ---------- the "More" menu ----------
    var menu = $('mx-menu');
    if ($('mx-more-btn')) $('mx-more-btn').addEventListener('click', function (ev) {
        ev.stopPropagation();
        var r = this.getBoundingClientRect();
        menu.querySelector('[data-mx=record]').style.display = host() ? 'block' : 'none';
        menu.querySelector('[data-mx=rooms]').style.display = host() ? 'block' : 'none';
        menu.querySelector('[data-mx=recordings]').style.display = host() ? 'block' : 'none';
        menu.querySelector('[data-mx=record]').textContent = recorder ? 'Stop recording' : 'Start recording';
        menu.style.display = 'block';
        var w = menu.offsetWidth, h = menu.offsetHeight;
        menu.style.left = Math.max(8, Math.min(window.innerWidth - w - 8, r.left)) + 'px';
        menu.style.top = (r.top > h + 12 ? r.top - h - 6 : r.bottom + 6) + 'px';
    });
    document.addEventListener('click', function () { menu.style.display = 'none'; });
    menu.addEventListener('click', function (ev) {
        var k = ev.target.getAttribute('data-mx'); if (!k) return;
        menu.style.display = 'none';
        ({ record: toggleRecording, rooms: rooms, notes: notes, recordings: recordings })[k]();
    });
    function dialog(title, html) { $('mx-dlg-title').textContent = title; $('mx-dlg-body').innerHTML = html; $('mx-dialog').style.display = 'flex'; }
    $('mx-dlg-close').addEventListener('click', function () { $('mx-dialog').style.display = 'none'; });
    var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };

    // ---------- recording: every tile and every voice, in this browser ----------
    var recorder = null, chunks = [], recStart = 0, drawTimer = null, audioCtx = null;
    function toggleRecording() {
        if (recorder) { recorder.stop(); return; }
        if (!window.MediaRecorder) { toast('This browser cannot record. Use Chrome, Edge or Firefox on a computer.', 6000); return; }
        var canvas = document.createElement('canvas'); canvas.width = 1280; canvas.height = 720;
        var g = canvas.getContext('2d');
        drawTimer = setInterval(function () {
            var vids = Array.prototype.slice.call(document.querySelectorAll('#video-grid video')).filter(function (v) { return v.videoWidth; });
            g.fillStyle = '#111827'; g.fillRect(0, 0, 1280, 720);
            var n = Math.max(1, vids.length), cols = Math.ceil(Math.sqrt(n)), rows = Math.ceil(n / cols), w = 1280 / cols, h = 720 / rows;
            vids.forEach(function (v, i) {
                var x = (i % cols) * w, y = Math.floor(i / cols) * h, s = Math.min(w / v.videoWidth, h / v.videoHeight), dw = v.videoWidth * s, dh = v.videoHeight * s;
                g.drawImage(v, x + (w - dw) / 2, y + (h - dh) / 2, dw, dh);
            });
        }, 1000 / 20);
        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        var mix = audioCtx.createMediaStreamDestination();
        document.querySelectorAll('#video-grid video').forEach(function (v) {
            if (v.srcObject && v.srcObject.getAudioTracks().length) { try { audioCtx.createMediaStreamSource(v.srcObject).connect(mix); } catch (e) {} }
        });
        var stream = new MediaStream(canvas.captureStream(20).getVideoTracks().concat(mix.stream.getAudioTracks()));
        chunks = []; recorder = new MediaRecorder(stream, { mimeType: MediaRecorder.isTypeSupported('video/webm;codecs=vp9,opus') ? 'video/webm;codecs=vp9,opus' : 'video/webm' });
        recorder.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
        recorder.onstop = function () {
            clearInterval(drawTimer); try { audioCtx.close(); } catch (e) {}
            var blob = new Blob(chunks, { type: 'video/webm' }), secs = Math.round((Date.now() - recStart) / 1000);
            recorder = null;
            axios.post(base + '/recording', { on: false }).catch(function () {});
            var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'meeting-' + new Date().toISOString().slice(0, 16).replace(/[:T]/g, '-') + '.webm'; a.click();
            var fd = new FormData(); fd.append('file', blob, 'meeting.webm'); fd.append('seconds', secs);
            toast('Recording stopped: saved on this computer, now saving with the meeting ...', 6000);
            axios.post(base + '/recordings', fd).then(function () { toast('Recording saved with the meeting (More > Recordings).'); })
                .catch(function (e) { toast('Saved on this computer only: ' + msg(e), 7000); });
        };
        axios.post(base + '/recording', { on: true }).then(function () {
            recStart = Date.now(); recorder.start(5000);
            toast('Recording. Everyone sees the red "Recording" sign. More > Stop recording to end.');
        }).catch(function (e) { clearInterval(drawTimer); recorder = null; toast(msg(e)); });
    }
    function recordings() {
        axios.get(base + '/recordings').then(function (r) {
            var list = r.data.data;
            dialog('Recordings', list.length ? list.map(function (x) {
                return '<p style="margin:6px 0"><a class="mx-btn line" href="' + esc(x.url) + '">Download</a> ' + esc(new Date(x.at).toLocaleString()) + ' - ' + Math.round(x.seconds / 60) + ' min, ' + (x.size / 1048576).toFixed(1) + ' MB</p>';
            }).join('') : '<p>No recordings yet.</p>');
        }).catch(function (e) { toast(msg(e)); });
    }

    // ---------- breakout rooms ----------
    function rooms() {
        axios.get(base + '/participants').then(function (r) {
            var people = (r.data.data || []).filter(function (p) { return p.id !== me && !p.is_guest; });
            if (!people.length) { toast('Nobody else is in the meeting yet.'); return; }
            dialog('Breakout rooms', '<label>Number of rooms <input id="mx-n" type="number" min="1" max="10" value="' + Math.min(2, people.length) + '" style="width:70px;border:1px solid #d1d5db;border-radius:6px;padding:4px 6px"></label>'
                + '<p style="color:#6b7280;margin:8px 0">People are spread over the rooms in turn; you stay in the main room and can call everyone back. Guests stay in the main room.</p>'
                + '<div id="mx-plan"></div><div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px"><button type="button" class="mx-btn" id="mx-open">Open rooms</button><button type="button" class="mx-btn line" id="mx-back">Call everyone back</button></div>');
            var plan = function () {
                var n = Math.max(1, Math.min(10, parseInt($('mx-n').value, 10) || 1)), out = [];
                for (var i = 0; i < n; i++) out.push({ name: 'Room ' + (i + 1), participants: [] });
                people.forEach(function (p, i) { out[i % n].participants.push(p.id); });
                $('mx-plan').innerHTML = out.map(function (o) { return '<p style="margin:4px 0"><b>' + o.name + ':</b> ' + esc(o.participants.map(function (id) { return people.find(function (p) { return p.id === id; }).display_name; }).join(', ') || '-') + '</p>'; }).join('');
                return out;
            };
            plan(); $('mx-n').addEventListener('input', plan);
            $('mx-open').addEventListener('click', function () {
                axios.post(base + '/breakouts', { rooms: plan() }).then(function (r2) {
                    $('mx-dlg-body').innerHTML = '<p>Rooms are open; people are being moved. You can visit a room:</p>' + r2.data.data.map(function (m) { return '<p style="margin:6px 0"><a class="mx-btn line" href="' + esc(m.url) + '" target="_blank" rel="noopener">' + esc(m.name) + '</a></p>'; }).join('');
                }).catch(function (e) { toast(msg(e)); });
            });
            $('mx-back').addEventListener('click', function () { axios.post(base + '/breakouts/close').then(function (r3) { toast(r3.data.data.closed + ' room(s) closed; everyone is coming back.'); $('mx-dialog').style.display = 'none'; }).catch(function (e) { toast(msg(e)); }); });
        }).catch(function (e) { toast(msg(e)); });
    }

    // ---------- meeting notes (summary of the captions) ----------
    function notes() {
        dialog('Meeting notes', '<p style="color:#6b7280">Made from the captions of this meeting (and its breakout rooms). Turn on CC while talking so there is something to summarise.</p><div id="mx-notes"><p>Loading ...</p></div>'
            + '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px"><button type="button" class="mx-btn" id="mx-make">Make notes now</button><button type="button" class="mx-btn line" id="mx-copy">Copy</button></div>');
        var show = function (text) { $('mx-notes').innerHTML = text ? '<pre>' + esc(text) + '</pre>' : '<p>No notes yet.</p>'; $('mx-notes').dataset.text = text || ''; };
        axios.get(base + '/summary').then(function (r) { show(r.data.data.summary); }).catch(function () { show(''); });
        $('mx-make').addEventListener('click', function () {
            this.disabled = true; $('mx-notes').innerHTML = '<p>Writing the notes ...</p>';
            var b = this;
            axios.post(base + '/summary').then(function (r) { show(r.data.data.summary); }).catch(function (e) { show(''); toast(msg(e), 7000); }).finally(function () { b.disabled = false; });
        });
        $('mx-copy').addEventListener('click', function () { navigator.clipboard && navigator.clipboard.writeText($('mx-notes').dataset.text || '').then(function () { toast('Copied.'); }); });
    }
});
</script>
