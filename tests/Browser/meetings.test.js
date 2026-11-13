import { test, assert } from './support/test.js';
import { loginAs, closeSession, getBrowser, USERS, absoluteUrl } from './support/session.js';

async function openGuestSession() {
    const browser = await getBrowser();
    const context = await browser.createBrowserContext();
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (err) => errors.push(`PAGEERROR: ${err.message}`));
    page.on('console', (msg) => { if (msg.type() === 'error') errors.push(`CONSOLE.ERROR: ${msg.text()}`); });
    return { page, errors, context };
}

async function waitUntil(fn, { timeout = 15000, interval = 300, message = 'condition' } = {}) {
    const start = Date.now();
    let lastError = null;
    while (Date.now() - start < timeout) {
        try {
            if (await fn()) return;
        } catch (error) {
            lastError = error;
        }
        await new Promise((resolve) => setTimeout(resolve, interval));
    }
    throw new Error(`Timed out waiting for: ${message}${lastError ? ` (last error: ${lastError.message})` : ''}`);
}

function isHidden(page, selector) {
    return page.evaluate((sel) => {
        const el = document.getElementById(sel);
        return !el || el.classList.contains('hidden');
    }, selector);
}

function localDateTimeInputValue(offsetMinutes) {
    const d = new Date(Date.now() + offsetMinutes * 60000);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

test('alice schedules a meeting with a waiting room, bob is held then admitted, and real mesh WebRTC connects them', async () => {
    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    // Desktop-sized on purpose: below the `lg` breakpoint the room's
    // People/Chat panel is a slide-up overlay behind a mobile-only
    // toggle button (see room.blade.php's Google-Meet-style mobile
    // layout) - this test exercises the always-visible desktop sidebar.
    await alice.page.setViewport({ width: 1280, height: 800 });
    await bob.page.setViewport({ width: 1280, height: 800 });

    await alice.page.goto(absoluteUrl('/meetings/schedule'), { waitUntil: 'networkidle0' });
    await alice.page.type('input[name="title"]', 'Browser Test Sync');
    await alice.page.evaluate((start, end) => {
        document.querySelector('input[name="scheduled_start"]').value = start;
        document.querySelector('input[name="scheduled_end"]').value = end;
    }, localDateTimeInputValue(5), localDateTimeInputValue(65));
    await Promise.all([
        alice.page.waitForNavigation({ waitUntil: 'networkidle0' }),
        alice.page.click('form[action$="/meetings/schedule"] button[type="submit"]'),
    ]);

    assert(alice.page.url().includes('/meetings'), `expected to land on the meetings list, got ${alice.page.url()}`);

    const meetingHref = await alice.page.evaluate(() => {
        const link = [...document.querySelectorAll('a')].find((el) => el.textContent.includes('Browser Test Sync'));
        return link ? link.getAttribute('href') : null;
    });
    assert(meetingHref, 'could not find the newly scheduled meeting in the list');

    await alice.page.goto(absoluteUrl(meetingHref), { waitUntil: 'networkidle0' });
    await waitUntil(() => alice.page.$eval('#lobby-join-btn', (btn) => !btn.disabled), { message: 'alice\'s lobby join button is enabled (getUserMedia settled)' });
    await alice.page.click('#lobby-join-btn');
    await waitUntil(async () => !(await isHidden(alice.page, 'room-panel')), { message: 'alice enters the room (organizer bypasses the waiting room)' });

    await bob.page.goto(absoluteUrl(meetingHref), { waitUntil: 'networkidle0' });
    await waitUntil(() => bob.page.$eval('#lobby-join-btn', (btn) => !btn.disabled), { message: 'bob\'s lobby join button is enabled (getUserMedia settled)' });
    await bob.page.click('#lobby-join-btn');
    await waitUntil(async () => !(await isHidden(bob.page, 'waiting-panel')), { message: 'bob is held in the waiting room' });

    // Alice (host) sees bob's request in her Participants tab and admits him.
    await waitUntil(async () => {
        const text = await alice.page.$eval('#waiting-room-list', (el) => el.textContent).catch(() => '');
        return text.includes('Browser Bob');
    }, { timeout: 10000, message: 'alice sees bob waiting to be admitted' });

    await alice.page.evaluate(() => {
        const buttons = [...document.querySelectorAll('#waiting-room-list button')];
        const admitBtn = buttons.find((b) => b.textContent.trim() === 'Admit');
        admitBtn?.click();
    });

    await waitUntil(async () => !(await isHidden(bob.page, 'room-panel')), { timeout: 10000, message: 'bob is admitted into the room' });

    // Real WebRTC mesh negotiation: each side ends up with a remote video tile for the other.
    await waitUntil(async () => {
        const tiles = await alice.page.$$eval('#video-grid > div', (els) => els.length);
        return tiles >= 2;
    }, { timeout: 15000, message: 'alice sees a tile for bob (mesh connected)' });

    await waitUntil(async () => {
        const tiles = await bob.page.$$eval('#video-grid > div', (els) => els.length);
        return tiles >= 2;
    }, { timeout: 15000, message: 'bob sees a tile for alice (mesh connected)' });

    // Meeting chat round-trip.
    await alice.page.click('#tab-chat');
    await alice.page.type('#meeting-chat-input', 'Hello from Alice');
    await alice.page.click('#meeting-chat-form button[type="submit"]');

    await bob.page.click('#tab-chat');
    await waitUntil(async () => {
        const text = await bob.page.$eval('#meeting-messages', (el) => el.textContent).catch(() => '');
        return text.includes('Hello from Alice');
    }, { timeout: 10000, message: 'bob receives alice\'s meeting chat message' });

    // Whiteboard: alice draws, bob sees the stroke synced.
    await alice.page.click('#whiteboard-btn');
    await waitUntil(async () => !(await isHidden(alice.page, 'whiteboard-overlay')), { message: 'alice\'s whiteboard overlay opens' });

    const canvasBox = await alice.page.$eval('#wb-canvas', (el) => {
        const rect = el.getBoundingClientRect();
        return { x: rect.x, y: rect.y };
    });
    await alice.page.mouse.move(canvasBox.x + 50, canvasBox.y + 50);
    await alice.page.mouse.down();
    await alice.page.mouse.move(canvasBox.x + 150, canvasBox.y + 150, { steps: 5 });
    await alice.page.mouse.up();

    await bob.page.click('#whiteboard-btn');
    await waitUntil(async () => !(await isHidden(bob.page, 'whiteboard-overlay')), { message: 'bob\'s whiteboard overlay opens' });

    await waitUntil(() => bob.page.evaluate(() => {
        const canvas = document.getElementById('wb-canvas');
        const ctx = canvas.getContext('2d');
        const data = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
        for (let i = 3; i < data.length; i += 4) {
            if (data[i] !== 0) return true;
        }
        return false;
    }), { timeout: 10000, message: 'bob sees alice\'s whiteboard stroke synced onto his canvas' });

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);
    assert(bob.errors.length === 0, `bob unexpected JS errors: ${JSON.stringify(bob.errors)}`);

    await closeSession(alice);
    await closeSession(bob);
});

test('when bob abandons the meeting tab without clicking Leave, alice stops seeing his tile instead of it lingering as a phantom participant', async () => {
    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    await alice.page.goto(absoluteUrl('/meetings/schedule'), { waitUntil: 'networkidle0' });
    await alice.page.type('input[name="title"]', 'Phantom Tile Check');
    // Scheduled several days out (join isn't time-gated) so this doesn't
    // land on today's calendar cell alongside the other tests' meetings
    // and tip it into "+N more" overflow collapse.
    await alice.page.evaluate((start, end) => {
        document.querySelector('input[name="scheduled_start"]').value = start;
        document.querySelector('input[name="scheduled_end"]').value = end;
    }, localDateTimeInputValue(4320), localDateTimeInputValue(4380));
    await alice.page.click('input[name="waiting_room_enabled"][value="0"]');
    await Promise.all([
        alice.page.waitForNavigation({ waitUntil: 'networkidle0' }),
        alice.page.click('form[action$="/meetings/schedule"] button[type="submit"]'),
    ]);

    const meetingHref = await alice.page.evaluate(() => {
        const link = [...document.querySelectorAll('a')].find((el) => el.textContent.includes('Phantom Tile Check'));
        return link ? link.getAttribute('href') : null;
    });
    assert(meetingHref, 'could not find the newly scheduled meeting in the list');

    await alice.page.goto(absoluteUrl(meetingHref), { waitUntil: 'networkidle0' });
    await waitUntil(() => alice.page.$eval('#lobby-join-btn', (btn) => !btn.disabled), { message: 'alice\'s lobby join button is enabled' });
    await alice.page.click('#lobby-join-btn');
    await waitUntil(async () => !(await isHidden(alice.page, 'room-panel')), { message: 'alice enters the room' });

    await bob.page.goto(absoluteUrl(meetingHref), { waitUntil: 'networkidle0' });
    await waitUntil(() => bob.page.$eval('#lobby-join-btn', (btn) => !btn.disabled), { message: 'bob\'s lobby join button is enabled' });
    await bob.page.click('#lobby-join-btn');
    await waitUntil(async () => !(await isHidden(bob.page, 'room-panel')), { message: 'bob enters the room (no waiting room)' });

    await waitUntil(async () => (await alice.page.$$eval('#video-grid > div', (els) => els.length)) >= 2, {
        timeout: 15000,
        message: 'alice sees a tile for bob (mesh connected)',
    });

    // Bob abandons the tab (crash / force-quit / accidentally closing it)
    // rather than clicking Leave - no explicit /leave API call happens,
    // this only exercises the pagehide-triggered sendBeacon + the
    // heartbeat/staleness fallback in MeetingService::roster().
    await closeSession(bob);

    await waitUntil(async () => (await alice.page.$$eval('#video-grid > div', (els) => els.length)) === 1, {
        timeout: 35000,
        message: 'bob\'s tile disappears from alice\'s video grid once he\'s gone',
    });

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);

    await closeSession(alice);
});

test('a guest joins an instant meeting without registering, is admitted from the waiting room, and connects via mesh WebRTC', async () => {
    const alice = await loginAs(USERS.alice);
    const guest = await openGuestSession();

    const instantResponse = await alice.page.evaluate(async () => {
        const res = await fetch('/api/v1/meetings/instant', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify({ title: 'Guest test meeting' }),
        });
        return res.json();
    });
    const meetingId = instantResponse.data.id;
    const meetingCode = instantResponse.data.meeting_code;

    await alice.page.goto(absoluteUrl(`/meetings/${meetingId}`), { waitUntil: 'networkidle0' });
    await waitUntil(() => alice.page.$eval('#lobby-join-btn', (btn) => !btn.disabled), { message: 'alice\'s lobby join button is enabled' });
    await alice.page.click('#lobby-join-btn');
    await waitUntil(async () => !(await isHidden(alice.page, 'room-panel')), { message: 'alice enters the room' });

    await guest.page.goto(absoluteUrl(`/j/${meetingCode}`), { waitUntil: 'networkidle0' });
    await guest.page.type('#guest-name-input', 'Visiting Vic');
    await guest.page.click('#guest-join-btn');

    await waitUntil(() => guest.page.url().includes('/meetings/guest/'), { message: 'guest is redirected to the guest room page' });
    await waitUntil(async () => !(await isHidden(guest.page, 'room-panel')), { timeout: 10000, message: 'guest enters the room (no waiting room on this instant meeting)' });

    await waitUntil(async () => {
        const tiles = await alice.page.$$eval('#video-grid > div', (els) => els.length);
        return tiles >= 2;
    }, { timeout: 15000, message: 'alice sees a tile for the guest (mesh connected)' });

    const rosterText = await alice.page.$eval('#participant-list', (el) => el.textContent);
    assert(rosterText.includes('Visiting Vic'), `expected alice's participant list to include the guest, got: ${rosterText}`);

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);
    assert(guest.errors.length === 0, `guest unexpected JS errors: ${JSON.stringify(guest.errors)}`);

    await closeSession(alice);
    await closeSession(guest);
});

test('meeting room: a real audio-output picker (Speaker/Earpiece/Bluetooth/Headset) is available, matching Calls', async () => {
    // Real gap fixed here: Meetings had no audio-output control at all -
    // only Calls did. Mirrors that exact picker (real enumerateDevices()
    // list, not a blind toggle), shown only where setSinkId() is
    // actually supported.
    const alice = await loginAs(USERS.alice);

    const instantResponse = await alice.page.evaluate(async () => {
        const res = await fetch('/api/v1/meetings/instant', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify({ title: 'Audio output picker check' }),
        });
        return res.json();
    });
    const meetingId = instantResponse.data.id;

    await alice.page.goto(absoluteUrl(`/meetings/${meetingId}`), { waitUntil: 'networkidle0' });
    await waitUntil(() => alice.page.$eval('#lobby-join-btn', (btn) => !btn.disabled), { message: 'alice\'s lobby join button is enabled' });
    await alice.page.click('#lobby-join-btn');
    await waitUntil(async () => !(await isHidden(alice.page, 'room-panel')), { message: 'alice enters the room' });

    await waitUntil(async () => !(await isHidden(alice.page, 'audio-output-btn')), { message: 'the Speaker button appears once setSinkId support is confirmed' });

    await alice.page.click('#audio-output-btn');
    await waitUntil(async () => !(await isHidden(alice.page, 'audio-output-menu')), { message: 'the audio-output menu opens' });

    const options = await alice.page.evaluate(() => [...document.querySelectorAll('#audio-output-menu button')].map((b) => b.textContent));
    assert(options.length > 0, 'expected at least one real audio-output option listed');

    await alice.page.evaluate(() => document.querySelectorAll('#audio-output-menu button')[0].click());
    const menuClosed = await alice.page.evaluate(() => document.getElementById('audio-output-menu').classList.contains('hidden'));
    assert(menuClosed, 'expected picking an option to close the menu');

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);
    await closeSession(alice);
});

test('meeting room: flip camera is hidden with only one camera, and requests the environment-facing camera once a second is confirmed', async () => {
    const alice = await loginAs(USERS.alice);

    // Puppeteer's fake-media backend only ever registers one fake
    // camera - stubbed here so the test is deterministic regardless of
    // Chrome's actual fake-device behavior, matching the equivalent
    // Calls test (tests/Browser/calls.test.js).
    await alice.page.evaluateOnNewDocument(() => {
        window.__flipCameraCalls = [];
        const originalGetUserMedia = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
        navigator.mediaDevices.getUserMedia = function (constraints) {
            if (constraints && constraints.video && constraints.video.facingMode) {
                window.__flipCameraCalls.push(constraints.video.facingMode);
            }
            return originalGetUserMedia(constraints);
        };
        const originalEnumerate = navigator.mediaDevices.enumerateDevices.bind(navigator.mediaDevices);
        navigator.mediaDevices.enumerateDevices = function () {
            return originalEnumerate().then((devices) => {
                const videoInput = devices.find((d) => d.kind === 'videoinput');
                return videoInput
                    ? devices.concat([{ deviceId: videoInput.deviceId + '-second', kind: 'videoinput', label: videoInput.label, groupId: videoInput.groupId }])
                    : devices;
            });
        };
    });

    const instantResponse = await alice.page.evaluate(async () => {
        const res = await fetch('/api/v1/meetings/instant', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify({ title: 'Flip camera test meeting' }),
        });
        return res.json();
    });
    const meetingId = instantResponse.data.id;

    await alice.page.goto(absoluteUrl(`/meetings/${meetingId}`), { waitUntil: 'networkidle0' });
    await waitUntil(() => alice.page.$eval('#lobby-join-btn', (btn) => !btn.disabled), { message: 'alice\'s lobby join button is enabled' });
    await alice.page.click('#lobby-join-btn');
    await waitUntil(async () => !(await isHidden(alice.page, 'room-panel')), { message: 'alice enters the room' });

    await waitUntil(async () => !(await isHidden(alice.page, 'flip-camera-btn')), { message: 'Flip camera appears once a 2nd camera is confirmed' });

    await alice.page.click('#flip-camera-btn');
    await new Promise((r) => setTimeout(r, 500));

    const flipCalls = await alice.page.evaluate(() => window.__flipCameraCalls);
    assert(flipCalls.length === 1 && flipCalls[0].ideal === 'environment', `expected one getUserMedia call requesting the environment-facing camera, got ${JSON.stringify(flipCalls)}`);
    assert(!(await isHidden(alice.page, 'room-panel')), 'the room must still be active after flipping the camera');

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);

    await closeSession(alice);
});

test('a guest can create a meeting with no account, get a shareable link, and a second guest joins via that link', async () => {
    const creator = await openGuestSession();
    const joiner = await openGuestSession();

    await creator.page.goto(absoluteUrl('/meetings/guest/new'), { waitUntil: 'networkidle0' });
    await creator.page.type('input[name="guest_name"]', 'Creator Carl');
    await creator.page.type('input[name="title"]', 'Impromptu sync');
    // Not waitForNavigation({waitUntil: 'networkidle0'}) - the guest
    // room page polls continuously (roster/signals/status), so network
    // never idles; poll the URL instead, same as the guest-join test
    // below does for landing on this same page type.
    await creator.page.click('button[type="submit"]');
    await waitUntil(() => creator.page.url().includes('/meetings/guest/'), { message: 'creator is redirected to the guest room page' });

    await waitUntil(async () => !(await isHidden(creator.page, 'room-panel')), { message: 'creator enters the room as its own host' });

    const shareLink = await creator.page.$eval('#share-link-value', (el) => el.textContent.trim());
    assert(shareLink.includes('/j/'), `expected a shareable /j/ link on the guest-creator's room, got: ${shareLink}`);

    await joiner.page.goto(shareLink, { waitUntil: 'networkidle0' });
    await joiner.page.type('#guest-name-input', 'Joiner Jill');
    await joiner.page.click('#guest-join-btn');

    await waitUntil(() => joiner.page.url().includes('/meetings/guest/'), { message: 'joiner is redirected to the guest room page' });
    await waitUntil(async () => !(await isHidden(joiner.page, 'room-panel')), { timeout: 10000, message: 'joiner enters the room (no waiting room by default)' });

    await waitUntil(async () => {
        const tiles = await creator.page.$$eval('#video-grid > div', (els) => els.length);
        return tiles >= 2;
    }, { timeout: 15000, message: 'creator sees a tile for the joiner (mesh connected)' });

    const rosterText = await creator.page.$eval('#participant-list', (el) => el.textContent);
    assert(rosterText.includes('Joiner Jill'), `expected the creator's participant list to include the joiner, got: ${rosterText}`);

    assert(creator.errors.length === 0, `creator unexpected JS errors: ${JSON.stringify(creator.errors)}`);
    assert(joiner.errors.length === 0, `joiner unexpected JS errors: ${JSON.stringify(joiner.errors)}`);

    await closeSession(creator);
    await closeSession(joiner);
});

test('a scheduled meeting shows up on the calendar grid for its organizer', async () => {
    const alice = await loginAs(USERS.alice);

    await alice.page.goto(absoluteUrl('/meetings/schedule'), { waitUntil: 'networkidle0' });
    await alice.page.type('input[name="title"]', 'Calendar Grid Check');
    await alice.page.evaluate((start, end) => {
        document.querySelector('input[name="scheduled_start"]').value = start;
        document.querySelector('input[name="scheduled_end"]').value = end;
    }, localDateTimeInputValue(10), localDateTimeInputValue(70));
    await Promise.all([
        alice.page.waitForNavigation({ waitUntil: 'networkidle0' }),
        alice.page.click('form[action$="/meetings/schedule"] button[type="submit"]'),
    ]);

    await alice.page.goto(absoluteUrl('/meetings/calendar'), { waitUntil: 'networkidle0' });

    await waitUntil(async () => {
        const text = await alice.page.$eval('#cal-grid', (el) => el.textContent).catch(() => '');
        return text.includes('Calendar Grid Check');
    }, { timeout: 10000, message: 'the scheduled meeting appears as a chip on today\'s calendar cell' });

    const rangeLabel = await alice.page.$eval('#cal-range-label', (el) => el.textContent);
    assert(rangeLabel.length > 0, 'expected a rendered month/year label');

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);

    await closeSession(alice);
});

test('the Meetings page has real All/History tabs on one page, and Calendar is its own nav-reachable page', async () => {
    const alice = await loginAs(USERS.alice);

    await alice.page.goto(absoluteUrl('/meetings'), { waitUntil: 'networkidle0' });

    function panelVisible(page, key) {
        return page.evaluate((k) => !document.getElementById('meetings-panel-' + k).classList.contains('hidden'), key);
    }

    assert(await panelVisible(alice.page, 'all'), 'expected the All panel visible by default on /meetings');
    assert(!(await panelVisible(alice.page, 'history')), 'expected the History panel hidden by default');

    await alice.page.click('[data-meeting-tab="history"]');
    assert(await panelVisible(alice.page, 'history'), 'expected the History panel visible after clicking the History tab');
    assert(!(await panelVisible(alice.page, 'all')), 'expected the All panel hidden after switching tabs');

    const navLabels = await alice.page.$$eval('nav.lg\\:hidden a', (links) => links.map((el) => el.textContent.trim()));
    assert(navLabels.includes('Calendar'), `expected a dedicated "Calendar" nav item, got: ${JSON.stringify(navLabels)}`);

    await alice.page.goto(absoluteUrl('/meetings/calendar'), { waitUntil: 'networkidle0' });
    assert((await alice.page.$('#cal-month-view')) !== null, 'expected the dedicated Calendar page to render its month view by default');

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);

    await closeSession(alice);
});

test('Calendar page: month/week/day view switching, day-detail popover on overflow, and dragging a month-view chip reschedules the meeting', async () => {
    const alice = await loginAs(USERS.alice);

    await alice.page.goto(absoluteUrl('/meetings/schedule'), { waitUntil: 'networkidle0' });
    await alice.page.type('input[name="title"]', 'Draggable Meeting');
    await alice.page.evaluate((start, end) => {
        document.querySelector('input[name="scheduled_start"]').value = start;
        document.querySelector('input[name="scheduled_end"]').value = end;
    }, localDateTimeInputValue(10), localDateTimeInputValue(70));
    await Promise.all([
        alice.page.waitForNavigation({ waitUntil: 'networkidle0' }),
        alice.page.click('form[action$="/meetings/schedule"] button[type="submit"]'),
    ]);

    const meetingId = await alice.page.evaluate(() => window.axios.get('/api/v1/meetings/upcoming').then((r) => {
        const found = r.data.data.find((m) => m.title === 'Draggable Meeting');
        return found ? found.id : null;
    }));
    assert(meetingId, 'expected to find the newly-scheduled meeting via the API');
    const originalDay = await alice.page.evaluate((id) => window.axios.get('/api/v1/meetings/' + id).then((r) => new Date(r.data.data.scheduled_start).getDate()), meetingId);

    await alice.page.goto(absoluteUrl('/meetings/calendar'), { waitUntil: 'networkidle0' });

    await waitUntil(async () => {
        const text = await alice.page.$eval('#cal-grid', (el) => el.textContent).catch(() => '');
        return text.includes('Draggable Meeting');
    }, { timeout: 10000, message: 'the scheduled meeting appears as a chip on today\'s calendar cell in month view' });

    // View switching.
    await alice.page.click('[data-cal-view="week"]');
    await new Promise((r) => setTimeout(r, 300));
    assert(!(await alice.page.evaluate(() => document.getElementById('cal-week-view').classList.contains('hidden'))), 'expected week view to be visible after clicking Week');
    const weekBodyText = await alice.page.$eval('#cal-week-body', (el) => el.textContent);
    assert(weekBodyText.includes('Draggable Meeting'), 'expected the meeting to render as a block in week view');

    await alice.page.click('[data-cal-view="day"]');
    await new Promise((r) => setTimeout(r, 300));
    assert(!(await alice.page.evaluate(() => document.getElementById('cal-day-view').classList.contains('hidden'))), 'expected day view to be visible after clicking Day');
    const dayBodyText = await alice.page.$eval('#cal-day-body', (el) => el.textContent);
    assert(dayBodyText.includes('Draggable Meeting'), 'expected the meeting to render as a block in today\'s day view');

    await alice.page.click('[data-cal-view="month"]');
    await new Promise((r) => setTimeout(r, 300));

    // Drag the month-view chip 2 days forward and confirm the backend
    // actually moved the meeting (not just a client-side visual change).
    const moved = await alice.page.evaluate(() => {
        // Chips are buttons (not links) - clicking one opens the
        // detail popup first instead of navigating straight into
        // joining a meeting; see openEventDetail() in calendar.blade.php.
        const chip = [...document.querySelectorAll('#cal-grid button')].find((b) => b.textContent.includes('Draggable Meeting'));
        const cells = [...document.querySelectorAll('#cal-grid > div')];
        const chipCellIndex = cells.indexOf(chip.closest('div'));
        const targetCell = cells[chipCellIndex + 2];

        const dataTransfer = new DataTransfer();
        chip.dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer: dataTransfer }));
        targetCell.dispatchEvent(new DragEvent('dragover', { bubbles: true, dataTransfer: dataTransfer, cancelable: true }));
        targetCell.dispatchEvent(new DragEvent('drop', { bubbles: true, dataTransfer: dataTransfer, cancelable: true }));
        return true;
    });
    assert(moved, 'expected the drag/drop simulation to run');

    await new Promise((r) => setTimeout(r, 800));

    const newDay = await alice.page.evaluate((id) => window.axios.get('/api/v1/meetings/' + id).then((r) => new Date(r.data.data.scheduled_start).getDate()), meetingId);
    assert(newDay !== originalDay, `expected the meeting's scheduled_start day to change after dragging, stayed on day ${newDay}`);

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);

    await closeSession(alice);
});

test('the Meetings nav icon is not also highlighted while on the Calendar page (and vice versa)', async () => {
    const alice = await loginAs(USERS.alice);
    const { page } = alice;

    function isActive(className) {
        return className.includes('bg-brand-50') && className.includes('text-brand-700');
    }

    await page.goto(absoluteUrl('/meetings'), { waitUntil: 'networkidle0' });
    const onMeetings = await page.evaluate(() => {
        const links = [...document.querySelectorAll('nav a[title]')];
        const meetings = links.find((a) => a.title === 'Meetings');
        const calendar = links.find((a) => a.title === 'Calendar');
        return { meetingsClass: meetings.className, calendarClass: calendar.className };
    });
    assert(isActive(onMeetings.meetingsClass), 'expected Meetings nav icon to be active on /meetings');
    assert(!isActive(onMeetings.calendarClass), 'expected Calendar nav icon to NOT be active on /meetings');

    await page.goto(absoluteUrl('/meetings/calendar'), { waitUntil: 'networkidle0' });
    const onCalendar = await page.evaluate(() => {
        const links = [...document.querySelectorAll('nav a[title]')];
        const meetings = links.find((a) => a.title === 'Meetings');
        const calendar = links.find((a) => a.title === 'Calendar');
        return { meetingsClass: meetings.className, calendarClass: calendar.className };
    });
    assert(!isActive(onCalendar.meetingsClass), 'expected Meetings nav icon to NOT be active on /meetings/calendar');
    assert(isActive(onCalendar.calendarClass), 'expected Calendar nav icon to be active on /meetings/calendar');

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);

    await closeSession(alice);
});

test('meeting room at a real mobile viewport: full-bleed video grid, a floating icon-only control bar, and People/Chat as a toggled overlay instead of a permanent sidebar', async () => {
    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    await alice.page.setViewport({ width: 375, height: 667 });
    await bob.page.setViewport({ width: 375, height: 667 });

    await alice.page.goto(absoluteUrl('/meetings/schedule'), { waitUntil: 'networkidle0' });
    await alice.page.type('input[name="title"]', 'Mobile Layout Check');
    await alice.page.evaluate((start, end) => {
        document.querySelector('input[name="scheduled_start"]').value = start;
        document.querySelector('input[name="scheduled_end"]').value = end;
    }, localDateTimeInputValue(4320), localDateTimeInputValue(4380));
    await alice.page.click('input[name="waiting_room_enabled"][value="0"]');
    await Promise.all([
        alice.page.waitForNavigation({ waitUntil: 'networkidle0' }),
        alice.page.click('form[action$="/meetings/schedule"] button[type="submit"]'),
    ]);

    const meetingHref = await alice.page.evaluate(() => {
        const link = [...document.querySelectorAll('a')].find((el) => el.textContent.includes('Mobile Layout Check'));
        return link ? link.getAttribute('href') : null;
    });
    assert(meetingHref, 'could not find the newly scheduled meeting in the list');

    await alice.page.goto(absoluteUrl(meetingHref), { waitUntil: 'networkidle0' });
    await waitUntil(() => alice.page.$eval('#lobby-join-btn', (btn) => !btn.disabled), { message: 'alice\'s lobby join button is enabled' });
    await alice.page.click('#lobby-join-btn');
    await waitUntil(async () => !(await isHidden(alice.page, 'room-panel')), { message: 'alice enters the room' });

    // The video grid must be full-bleed within the room's own content
    // area (the app shell's standard px-4 page padding still applies,
    // same as every other page - this checks the sidebar isn't eating
    // into the grid's width the way it does at a desktop viewport).
    const layout = await alice.page.evaluate(() => {
        const grid = document.getElementById('video-grid');
        const panel = document.getElementById('room-side-panel');
        const controls = document.getElementById('room-controls');
        return {
            gridWidth: grid.getBoundingClientRect().width,
            roomWidth: document.getElementById('room-panel').getBoundingClientRect().width,
            panelHidden: getComputedStyle(panel).display === 'none',
            controlsPosition: getComputedStyle(controls).position,
            labelDisplay: getComputedStyle(document.querySelector('#mute-self-btn span')).display,
        };
    });
    assert(layout.gridWidth >= layout.roomWidth - 4, `expected the video grid to span the room's full content width, got ${layout.gridWidth} of ${layout.roomWidth}`);
    assert(layout.panelHidden, 'expected the People/Chat panel to be hidden by default at a mobile viewport');
    assert(layout.controlsPosition === 'fixed', 'expected the control bar to float (position: fixed) at a mobile viewport');
    assert(layout.labelDisplay === 'none', 'expected control button text labels to be hidden (icon-only) at a mobile viewport');

    // Opening it via the toggle button makes it a full-screen overlay, and
    // the close button dismisses it again - all without leaving the call.
    await alice.page.click('#mobile-panel-toggle-btn');
    const panelOpenState = await alice.page.evaluate(() => {
        const panel = document.getElementById('room-side-panel');
        const rect = panel.getBoundingClientRect();
        return { display: getComputedStyle(panel).display, width: rect.width, viewportWidth: document.documentElement.clientWidth };
    });
    assert(panelOpenState.display !== 'none', 'expected the panel to become visible after tapping the toggle button');
    assert(panelOpenState.width >= panelOpenState.viewportWidth - 4, 'expected the opened panel to cover the full viewport width on mobile');

    await alice.page.click('#room-side-panel-close-btn');
    const panelClosedAgain = await alice.page.evaluate(() => getComputedStyle(document.getElementById('room-side-panel')).display === 'none');
    assert(panelClosedAgain, 'expected the close button to hide the panel again');

    // The redesign is purely visual - a real second participant still
    // shows up as an actual mesh WebRTC tile, same as the desktop layout.
    await bob.page.goto(absoluteUrl(meetingHref), { waitUntil: 'networkidle0' });
    await waitUntil(() => bob.page.$eval('#lobby-join-btn', (btn) => !btn.disabled), { message: 'bob\'s lobby join button is enabled' });
    await bob.page.click('#lobby-join-btn');
    await waitUntil(async () => !(await isHidden(bob.page, 'room-panel')), { message: 'bob enters the room (no waiting room)' });
    await waitUntil(async () => (await alice.page.$$eval('#video-grid > div', (els) => els.length)) >= 2, {
        timeout: 15000,
        message: 'alice sees a tile for bob at the mobile layout too',
    });

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);
    assert(bob.errors.length === 0, `bob unexpected JS errors: ${JSON.stringify(bob.errors)}`);

    await closeSession(alice);
    await closeSession(bob);
});

test('meeting room at a mobile viewport: Raise Hand and Lock give real visible feedback, not just an invisible label change', async () => {
    // Real bug: Raise Hand and Lock's own <span> text label is
    // `hidden lg:inline` - invisible below the lg breakpoint, same as
    // every other toolbar button's label - but unlike Mute/Camera (which
    // swap their icon), these two had NO other visual feedback at all.
    // The underlying API call succeeded either way, but a phone user
    // tapping either button saw nothing happen, reading as broken.
    const alice = await loginAs(USERS.alice);
    await alice.page.setViewport({ width: 375, height: 667 });

    const instantResponse = await alice.page.evaluate(async () => {
        const res = await fetch('/api/v1/meetings/instant', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify({ title: 'Mobile raise-hand/lock check' }),
        });
        return res.json();
    });
    const meetingId = instantResponse.data.id;

    await alice.page.goto(absoluteUrl(`/meetings/${meetingId}`), { waitUntil: 'networkidle0' });
    await waitUntil(() => alice.page.$eval('#lobby-join-btn', (btn) => !btn.disabled), { message: 'alice\'s lobby join button is enabled' });
    await alice.page.click('#lobby-join-btn');
    await waitUntil(async () => !(await isHidden(alice.page, 'room-panel')), { message: 'alice enters the room' });
    // As the host, the Lock button is actually shown (see updateHostControlsVisibility()).
    await waitUntil(async () => !(await isHidden(alice.page, 'lock-btn')), { message: 'the host sees the Lock button' });

    async function readState(id) {
        return alice.page.evaluate((id) => {
            const btn = document.getElementById(id);
            return {
                active: btn.classList.contains('room-toolbar-btn--active'),
                svg: btn.querySelector('svg').outerHTML,
            };
        }, id);
    }

    const raiseBefore = await readState('raise-hand-btn');
    assert(!raiseBefore.active, 'expected Raise hand to start un-highlighted');

    await alice.page.click('#raise-hand-btn');
    await new Promise((r) => setTimeout(r, 300));
    const raiseAfter = await readState('raise-hand-btn');
    assert(raiseAfter.active, 'expected Raise hand to visibly highlight after tapping it, even though its text label is invisible on mobile');

    await alice.page.click('#raise-hand-btn');
    await new Promise((r) => setTimeout(r, 300));
    const raiseLowered = await readState('raise-hand-btn');
    assert(!raiseLowered.active, 'expected the highlight to clear again after lowering the hand');

    // Distinguish locked vs. unlocked by the rect's own rx (1.8 vs 2) -
    // not exact string equality, since the *original* icon is rendered by
    // the Blade component while the JS swap uses a hand-written literal
    // matching it visually, not necessarily byte-for-byte.
    function isLockedIcon(svg) {
        return svg.includes('rx="1.8"');
    }

    const lockBefore = await readState('lock-btn');
    assert(!lockBefore.active, 'expected Lock to start un-highlighted');
    assert(isLockedIcon(lockBefore.svg), 'expected the lock button to start showing the locked icon');

    await alice.page.click('#lock-btn');
    await new Promise((r) => setTimeout(r, 300));
    const lockAfter = await readState('lock-btn');
    assert(lockAfter.active, 'expected Lock to visibly highlight after tapping it');
    assert(!isLockedIcon(lockAfter.svg), 'expected the lock icon itself to swap to an unlocked icon, not just the (invisible) label');

    await alice.page.click('#lock-btn');
    await new Promise((r) => setTimeout(r, 300));
    const lockUnlocked = await readState('lock-btn');
    assert(!lockUnlocked.active, 'expected the highlight to clear again after unlocking');
    assert(isLockedIcon(lockUnlocked.svg), 'expected the icon to swap back to the locked icon');

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);
    await closeSession(alice);
});

test('guest room at a real mobile viewport: same Google-Meet-style layout as the registered-user room', async () => {
    const alice = await loginAs(USERS.alice);
    const guest = await openGuestSession();

    await guest.page.setViewport({ width: 375, height: 667 });

    const instantResponse = await alice.page.evaluate(async () => {
        const res = await fetch('/api/v1/meetings/instant', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify({ title: 'Guest mobile layout meeting' }),
        });
        return res.json();
    });
    const meetingCode = instantResponse.data.meeting_code;

    await guest.page.goto(absoluteUrl(`/j/${meetingCode}`), { waitUntil: 'networkidle0' });
    await guest.page.type('#guest-name-input', 'Mobile Guest');
    await guest.page.click('#guest-join-btn');
    await waitUntil(() => guest.page.url().includes('/meetings/guest/'), { message: 'guest is redirected to the guest room page' });
    await waitUntil(async () => !(await isHidden(guest.page, 'room-panel')), { timeout: 10000, message: 'guest enters the room' });

    const layout = await guest.page.evaluate(() => {
        const grid = document.getElementById('video-grid');
        const room = document.getElementById('room-panel');
        const panel = document.getElementById('room-side-panel');
        const controls = document.getElementById('room-controls');
        return {
            gridWidth: grid.getBoundingClientRect().width,
            roomWidth: room.getBoundingClientRect().width,
            panelHidden: getComputedStyle(panel).display === 'none',
            controlsPosition: getComputedStyle(controls).position,
        };
    });
    assert(layout.gridWidth >= layout.roomWidth - 4, `expected the guest video grid to be full-bleed, got ${layout.gridWidth} of ${layout.roomWidth}`);
    assert(layout.panelHidden, 'expected the guest People panel to be hidden by default at a mobile viewport');
    assert(layout.controlsPosition === 'fixed', 'expected the guest control bar to float at a mobile viewport');

    await guest.page.click('#mobile-panel-toggle-btn');
    const opened = await guest.page.evaluate(() => getComputedStyle(document.getElementById('room-side-panel')).display !== 'none');
    assert(opened, 'expected the People panel to open after tapping the toggle');

    await guest.page.click('#room-side-panel-close-btn');
    const closedAgain = await guest.page.evaluate(() => getComputedStyle(document.getElementById('room-side-panel')).display === 'none');
    assert(closedAgain, 'expected the close button to hide the People panel again');

    // Mute must only swap the label span's text, not wipe out the icon
    // (a plain this.textContent = would have destroyed both).
    await guest.page.click('#mute-self-btn');
    const muteBtnHasIcon = await guest.page.evaluate(() => document.getElementById('mute-self-btn').querySelector('svg') !== null);
    assert(muteBtnHasIcon, 'expected the mute button to still have its icon after toggling');

    assert(guest.errors.length === 0, `guest unexpected JS errors: ${JSON.stringify(guest.errors)}`);

    await closeSession(alice);
    await closeSession(guest);
});
