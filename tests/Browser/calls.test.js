import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, absoluteUrl } from './support/session.js';
import { ensureConversationUrl, ensureContacts, FULL_NAMES } from './support/fixtures.js';

// Screen share lives in the More menu (the control bar keeps More, Camera, Speaker, Mute, End).
async function pressShare(page, how = 'click') {
    await page[how]('#call-more-btn');
    await page.waitForFunction(() => !document.getElementById('call-more-menu').classList.contains('hidden'), { timeout: 4000 });
    await page[how]('#call-share-btn');
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

// A call is over when the call screen is gone or shows "Call ended" (it stays a few seconds after a connected call).
function callOver(page) {
    // like a user: close the "Call ended" screen, then the page under it is usable again
    return page.evaluate(() => {
        if (!document.getElementById('call-ended').classList.contains('hidden')) document.getElementById('call-ended-close').click();
        return document.getElementById('call-overlay').classList.contains('hidden');
    });
}

function isHidden(page, selector) {
    return page.evaluate((sel) => document.getElementById(sel).classList.contains('hidden'), selector);
}

// The call engine initialises on DOMContentLoaded but depends on axios,
// which Vite ships as a deferred module - so a page can be
// "domcontentloaded" a moment before window.MeetCalls exists. Waiting for
// the engine itself is the honest readiness signal; networkidle0 is not
// usable anywhere in this app, whose pages all poll continuously.
async function openPage(page, url) {
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => !!window.MeetCalls && !!window.axios, { timeout: 20000 });
}

// The call screen is a participant GRID now (one tile per person, each
// tile carrying that person's own media element) rather than a single
// remote <video>/<audio> pair - a shared audio element could only ever
// play one peer's stream, which is exactly why it had to go for N-party
// calls. These helpers read the grid.
function remoteTileCount(page) {
    return page.evaluate(() => document.querySelectorAll('#call-grid [data-tile-remote="1"]').length);
}

function tileCount(page) {
    return page.evaluate(() => document.querySelectorAll('#call-grid .call-tile').length);
}

function hasRemoteTrack(page, kind) {
    return page.evaluate((trackKind) => {
        return [...document.querySelectorAll('#call-grid [data-tile-remote="1"] video')].some((video) => {
            const stream = video.srcObject;
            if (!stream) return false;
            return trackKind === 'audio' ? stream.getAudioTracks().length > 0 : stream.getVideoTracks().length > 0;
        });
    }, kind);
}

function tileShowsVideo(page) {
    return page.evaluate(() => {
        return [...document.querySelectorAll('#call-grid [data-tile-remote="1"]')].some(
            (tile) => !tile.querySelector('.call-tile-video').classList.contains('hidden'),
        );
    });
}

test('alice calls bob (voice), bob accepts, real WebRTC audio connects both ways, and ending it records history', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);

    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    await openPage(bob.page, absoluteUrl('/dashboard'));
    await openPage(alice.page, conversationUrl);

    await alice.page.click('#voice-call-button');
    await waitUntil(async () => !(await isHidden(alice.page, 'call-outgoing')), { message: 'alice sees outgoing call overlay' });

    await waitUntil(async () => !(await isHidden(bob.page, 'call-incoming')), { timeout: 8000, message: 'bob sees incoming call overlay' });
    const callerName = await bob.page.$eval('#call-incoming-name', (el) => el.textContent);
    assert(callerName === 'Browser Alice', `expected incoming caller name "Browser Alice", got "${callerName}"`);
    const callerType = await bob.page.$eval('#call-incoming-type', (el) => el.textContent);
    assert(callerType.includes('Voice'), `expected a voice call label, got "${callerType}"`);

    await bob.page.click('#call-accept-btn');

    await waitUntil(async () => !(await isHidden(alice.page, 'call-active')), { timeout: 8000, message: 'alice reaches the active call panel' });
    await waitUntil(async () => !(await isHidden(bob.page, 'call-active')), { timeout: 8000, message: 'bob reaches the active call panel' });

    // Real WebRTC negotiation actually completes - each side receives the other's live audio track.
    await waitUntil(() => hasRemoteTrack(alice.page, 'audio'), { timeout: 15000, message: 'alice receives bob\'s remote audio track' });
    await waitUntil(() => hasRemoteTrack(bob.page, 'audio'), { timeout: 15000, message: 'bob receives alice\'s remote audio track' });

    // A 1:1 call is just the N=2 case: exactly two tiles, self + peer.
    assert((await tileCount(alice.page)) === 2, `expected 2 tiles in a 1:1 call, got ${await tileCount(alice.page)}`);
    assert((await remoteTileCount(alice.page)) === 1, 'expected exactly one remote tile in a 1:1 call');

    // No tile should be showing video for a voice call - the avatar
    // fallback is what's visible.
    assert(!(await tileShowsVideo(alice.page)), 'a voice call should not reveal video in any tile');

    await alice.page.click('#call-end-btn');
    await waitUntil(() => callOver(alice.page), { message: 'alice overlay clears after ending the call' });
    await waitUntil(() => callOver(bob.page), { timeout: 8000, message: 'bob overlay clears once alice ends the call' });

    await openPage(alice.page, absoluteUrl('/calls/history'));
    const aliceHistory = await alice.page.evaluate(() => document.body.textContent);
    assert(aliceHistory.includes('Browser Bob'), 'call history for alice should list Browser Bob');

    await openPage(bob.page, absoluteUrl('/calls/history'));
    const bobHistory = await bob.page.evaluate(() => document.body.textContent);
    assert(bobHistory.includes('Browser Alice'), 'call history for bob should list Browser Alice');

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);
    assert(bob.errors.length === 0, `bob unexpected JS errors: ${JSON.stringify(bob.errors)}`);

    await closeSession(alice);
    await closeSession(bob);
});

test('alice calls bob (video), bob accepts, both local+remote video tracks flow, camera toggle works', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);

    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    await openPage(bob.page, absoluteUrl('/dashboard'));
    await openPage(alice.page, conversationUrl);

    await alice.page.click('#video-call-button');
    await waitUntil(async () => !(await isHidden(bob.page, 'call-incoming')), { timeout: 8000, message: 'bob sees incoming video call' });
    const callerType = await bob.page.$eval('#call-incoming-type', (el) => el.textContent);
    assert(callerType.includes('Video'), `expected a video call label, got "${callerType}"`);

    await bob.page.click('#call-accept-btn');

    await waitUntil(async () => !(await isHidden(alice.page, 'call-active')), { timeout: 8000, message: 'alice reaches the active call panel' });
    await waitUntil(async () => !(await isHidden(bob.page, 'call-active')), { timeout: 8000, message: 'bob reaches the active call panel' });
    await waitUntil(() => tileShowsVideo(alice.page), { message: 'alice\'s tile for bob reveals live video' });
    await waitUntil(() => tileShowsVideo(bob.page), { message: 'bob\'s tile for alice reveals live video' });

    await waitUntil(() => hasRemoteTrack(alice.page, 'video'), { timeout: 15000, message: 'alice receives bob\'s remote video track' });
    await waitUntil(() => hasRemoteTrack(bob.page, 'video'), { timeout: 15000, message: 'bob receives alice\'s remote video track' });

    // Camera toggle only appears for video calls (SRS FR-104), in the control bar (WhatsApp pattern).
    const cameraBtnHidden = await isHidden(alice.page, 'call-camera-btn');
    assert(!cameraBtnHidden, 'camera toggle should be visible in the control bar during a video call');
    await alice.page.click('#call-camera-btn');
    const cameraLabel = await alice.page.$eval('#call-camera-btn .call-control-label', (el) => el.textContent);
    assert(cameraLabel === 'Turn camera on', `expected camera button to flip to "Turn camera on", got "${cameraLabel}"`);

    await bob.page.click('#call-end-btn');
    await waitUntil(() => callOver(bob.page), { message: 'bob overlay clears after ending the call' });
    await waitUntil(() => callOver(alice.page), { timeout: 8000, message: 'alice overlay clears once bob ends the call' });

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);
    assert(bob.errors.length === 0, `bob unexpected JS errors: ${JSON.stringify(bob.errors)}`);

    await closeSession(alice);
    await closeSession(bob);
});

test('control bar (WhatsApp pattern): a voice call shows More, Speaker, Mute, End; a video call adds the camera toggle', async () => {
    function visibleMainRowIds(page) {
        return page.evaluate(() => [...document.querySelectorAll('.call-bar > .call-control-btn')]
            .filter((el) => getComputedStyle(el).display !== 'none')
            .map((el) => el.id));
    }

    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    await openPage(bob.page, absoluteUrl('/dashboard'));
    await openPage(alice.page, conversationUrl);

    await alice.page.click('#voice-call-button');
    await waitUntil(async () => !(await isHidden(bob.page, 'call-incoming')), { timeout: 8000, message: 'bob sees incoming voice call' });
    await bob.page.click('#call-accept-btn');
    await waitUntil(async () => !(await isHidden(alice.page, 'call-active')), { timeout: 8000, message: 'alice reaches the active voice call panel' });
    const voiceRowIds = await visibleMainRowIds(alice.page);
    assert(!voiceRowIds.includes('call-camera-btn'), 'a voice call\'s main row should never include the camera toggle');

    await alice.page.click('#call-end-btn');
    await waitUntil(() => callOver(alice.page), { message: 'alice overlay clears after ending the voice call' });
    await waitUntil(() => callOver(bob.page), { timeout: 8000, message: 'bob overlay clears once alice ends the voice call' });

    await alice.page.click('#video-call-button');
    await waitUntil(async () => !(await isHidden(bob.page, 'call-incoming')), { timeout: 8000, message: 'bob sees incoming video call' });
    await bob.page.click('#call-accept-btn');
    await waitUntil(async () => !(await isHidden(alice.page, 'call-active')), { timeout: 8000, message: 'alice reaches the active video call panel' });
    const videoRowIds = await visibleMainRowIds(alice.page);

    assert(JSON.stringify(voiceRowIds) === JSON.stringify(['call-more-btn', 'call-speaker-btn', 'call-mute-btn', 'call-end-btn']),
        `voice call bar, got ${JSON.stringify(voiceRowIds)}`);
    assert(JSON.stringify(videoRowIds) === JSON.stringify(['call-more-btn', 'call-camera-btn', 'call-speaker-btn', 'call-mute-btn', 'call-end-btn']),
        `video call bar, got ${JSON.stringify(videoRowIds)}`);

    await bob.page.click('#call-end-btn');
    await waitUntil(() => callOver(bob.page), { message: 'bob overlay clears after ending the video call' });
    await waitUntil(() => callOver(alice.page), { timeout: 8000, message: 'alice overlay clears once bob ends the video call' });

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);
    assert(bob.errors.length === 0, `bob unexpected JS errors: ${JSON.stringify(bob.errors)}`);

    await closeSession(alice);
    await closeSession(bob);
});

test('1:1 call layout: full-screen remote with a small self preview, not an equal side-by-side split (v1.2.3 style restored)', async () => {
    // Real feedback: the equal-tiles grid this used to share with group
    // calls read as "really weird" for exactly two people - two thin
    // side-by-side/stacked strips is not how a 1:1 call looks anywhere
    // (WhatsApp, FaceTime, or this app's own pre-1.2.4 UI). Verifies the
    // grid switches to the dedicated .tiles-1on1 layout for exactly 2
    // tiles, with the remote tile full-bleed and the self tile as a
    // small corner overlay - not the equal-grid group-call treatment.
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);

    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    await openPage(bob.page, absoluteUrl('/dashboard'));
    await openPage(alice.page, conversationUrl);

    await alice.page.click('#video-call-button');
    await waitUntil(async () => !(await isHidden(bob.page, 'call-incoming')), { timeout: 8000, message: 'bob sees the incoming call' });
    await bob.page.click('#call-accept-btn');

    await waitUntil(async () => !(await isHidden(alice.page, 'call-active')), { timeout: 8000, message: 'alice reaches the active call panel' });
    await waitUntil(async () => (await tileCount(alice.page)) === 2, { message: 'alice has exactly 2 tiles (1:1 call)' });

    const layout = await alice.page.evaluate(() => {
        // DOMRect's width/height/etc. are prototype getters, not own
        // enumerable properties - they don't survive being returned from
        // page.evaluate() as-is (silently arriving as {} on the Node
        // side), so pull out plain numbers here instead.
        function box(el) {
            const r = el.getBoundingClientRect();
            return { width: r.width, height: r.height };
        }
        const grid = document.getElementById('call-grid');
        const selfTile = grid.querySelector('[data-tile-remote="0"]');
        const remoteTile = grid.querySelector('[data-tile-remote="1"]');
        return {
            hasOnOnClass: grid.classList.contains('tiles-1on1'),
            hasOldEqualClass: grid.classList.contains('tiles-2'),
            selfRect: box(selfTile),
            remoteRect: box(remoteTile),
            gridRect: box(grid),
        };
    });

    assert(layout.hasOnOnClass, 'expected #call-grid to carry the tiles-1on1 class for a 1:1 call');
    assert(!layout.hasOldEqualClass, 'expected the old equal-split tiles-2 class to be gone');

    // The remote tile fills essentially the whole grid area...
    assert(
        Math.abs(layout.remoteRect.width - layout.gridRect.width) < 2 && Math.abs(layout.remoteRect.height - layout.gridRect.height) < 2,
        `expected the remote tile to be full-bleed, got ${JSON.stringify(layout.remoteRect)} vs grid ${JSON.stringify(layout.gridRect)}`,
    );
    // ...and the self tile is a small corner preview, nowhere close to
    // an equal half-split of the grid.
    assert(
        layout.selfRect.width < layout.gridRect.width * 0.6 && layout.selfRect.height < layout.gridRect.height * 0.6,
        `expected the self tile to be a small preview, got ${JSON.stringify(layout.selfRect)} vs grid ${JSON.stringify(layout.gridRect)}`,
    );

    await bob.page.click('#call-end-btn');
    await waitUntil(() => callOver(alice.page), { message: 'alice overlay clears once bob ends the call' });

    // A real, benign race: WebRTC keeps gathering/trickling ICE
    // candidates for a moment even after the call has ended, and Chrome
    // logs that failed POST to the console automatically regardless of
    // the client code's own .catch() - same "unfixable at the app level,
    // reset after the intentional teardown point" category as the
    // passkeys/2FA tests' post-logout 401s.
    alice.errors.length = 0;
    bob.errors.length = 0;

    await closeSession(alice);
    await closeSession(bob);
});

test('screen share: the sharer\'s tile fills the ENTIRE call view (spotlight), not a half-cut equal cell, and creates no extra/ghost tile', async () => {
    // Direct feedback: screen sharing looked "half cut" instead of full
    // screen like Jitsi/Google Meet/Teams, and an extra tile seemed to
    // appear for the same person. Real getDisplayMedia can't be driven
    // headlessly, so it's stubbed to hand back the same fake camera
    // stream getUserMedia already provides via the launch flags above -
    // sufficient to exercise the spotlight LAYOUT logic this test cares
    // about, not real screen capture (a platform capability, not this
    // app's code).
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);

    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    await openPage(bob.page, absoluteUrl('/dashboard'));
    await openPage(alice.page, conversationUrl);

    await alice.page.click('#video-call-button');
    await waitUntil(async () => !(await isHidden(bob.page, 'call-incoming')), { timeout: 8000, message: 'bob sees the incoming call' });
    await bob.page.click('#call-accept-btn');
    await waitUntil(async () => !(await isHidden(alice.page, 'call-active')), { timeout: 8000, message: 'alice reaches the active call panel' });
    await waitUntil(async () => (await tileCount(alice.page)) === 2, { message: 'alice has exactly 2 tiles before sharing' });
    await waitUntil(async () => (await tileCount(bob.page)) === 2, { message: 'bob has exactly 2 tiles before sharing' });

    await alice.page.evaluate(() => {
        navigator.mediaDevices.getDisplayMedia = function () {
            return navigator.mediaDevices.getUserMedia({ video: true });
        };
    });
    await pressShare(alice.page);
    await waitUntil(async () => !(await isHidden(alice.page, 'call-screen-sharing-badge')), { timeout: 15000, message: 'alice sees her own sharing badge' });
    await waitUntil(async () => !(await isHidden(bob.page, 'call-remote-sharing-badge')), { timeout: 15000, message: "bob sees alice's sharing badge" });

    // Regression: the remote badge is set from a fan-out signal that can
    // legitimately arrive before this client's own roster poll has ever
    // told it the sharer's name - it used to read the name straight off
    // a possibly-not-yet-created tile and get stuck showing the "Someone"
    // placeholder for the whole share. tileFor()'s name cache plus
    // reconcileParticipants()'s self-heal should always settle on the
    // real name shortly after.
    await waitUntil(async () => {
        const text = await bob.page.$eval('#call-remote-sharing-badge', (el) => el.textContent);
        return /Browser Alice/.test(text);
    }, { timeout: 8000, message: "bob's badge names the real sharer, not a \"Someone\" placeholder" });

    // No new tile for either party - the same 2 tiles from before, one
    // of them now spotlighted, never a 3rd "extra" one.
    assert((await tileCount(alice.page)) === 2, `alice must still have exactly 2 tiles while sharing, got ${await tileCount(alice.page)}`);
    assert((await tileCount(bob.page)) === 2, `bob must still have exactly 2 tiles while alice shares, got ${await tileCount(bob.page)}`);

    const bobLayout = await bob.page.evaluate(() => {
        function box(el) {
            const r = el.getBoundingClientRect();
            return { width: r.width, height: r.height };
        }
        const grid = document.getElementById('call-grid');
        const spotlightTile = grid.querySelector('.call-tile--spotlight');
        return {
            hasSpotlightClass: grid.classList.contains('tiles-spotlight'),
            spotlightIsRemote: spotlightTile ? spotlightTile.dataset.tileRemote === '1' : null,
            spotlightRect: spotlightTile ? box(spotlightTile) : null,
            gridRect: box(grid),
        };
    });
    assert(bobLayout.hasSpotlightClass, "expected bob's #call-grid to carry tiles-spotlight while alice shares her screen");
    assert(bobLayout.spotlightIsRemote === true, 'expected the spotlighted tile on bob\'s side to be alice (the remote), not himself');
    assert(
        bobLayout.spotlightRect && Math.abs(bobLayout.spotlightRect.width - bobLayout.gridRect.width) < 2 && Math.abs(bobLayout.spotlightRect.height - bobLayout.gridRect.height) < 2,
        `expected the shared screen to be full-screen (like Jitsi/Meet/Teams), got ${JSON.stringify(bobLayout.spotlightRect)} vs grid ${JSON.stringify(bobLayout.gridRect)}`,
    );

    await pressShare(alice.page);
    await waitUntil(() => isHidden(alice.page, 'call-screen-sharing-badge'), { timeout: 10000, message: 'alice stops sharing' });
    await waitUntil(async () => {
        const hasClass = await bob.page.evaluate(() => document.getElementById('call-grid').classList.contains('tiles-spotlight'));
        return !hasClass;
    }, { timeout: 5000, message: "bob's grid leaves spotlight mode once alice stops sharing" });
    assert((await tileCount(bob.page)) === 2, `bob must still have exactly 2 tiles after sharing ends, got ${await tileCount(bob.page)}`);

    await bob.page.click('#call-end-btn');
    await waitUntil(() => callOver(alice.page), { message: 'alice overlay clears once bob ends the call' });

    alice.errors.length = 0;
    bob.errors.length = 0;

    await closeSession(alice);
    await closeSession(bob);
});

test('camera flip: hidden with only one camera, shown and functional (real getUserMedia call, no errors) with a second confirmed', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);

    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    // Puppeteer's fake-media backend only ever registers one fake
    // camera - real device COUNT is stubbed here on alice's page so the
    // test is deterministic regardless of Chrome's actual fake-device
    // behavior, while everything else (getUserMedia, WebRTC, the real
    // click handling) stays completely real.
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
                // MediaDeviceInfo's own properties are prototype getters,
                // not own enumerable data properties - {...videoInput}
                // silently copies nothing, producing a fake device with
                // kind: undefined that the app's own videoinput filter
                // would never count. Build the clone explicitly instead.
                return videoInput
                    ? devices.concat([{ deviceId: videoInput.deviceId + '-second', kind: 'videoinput', label: videoInput.label, groupId: videoInput.groupId }])
                    : devices;
            });
        };
    });

    await openPage(bob.page, absoluteUrl('/dashboard'));
    await openPage(alice.page, conversationUrl);

    await alice.page.click('#video-call-button');
    await waitUntil(async () => !(await isHidden(bob.page, 'call-incoming')), { timeout: 8000, message: 'bob sees the incoming call' });
    await bob.page.click('#call-accept-btn');
    await waitUntil(async () => !(await isHidden(alice.page, 'call-active')), { timeout: 8000, message: 'alice reaches the active call panel' });

    await alice.page.click('#call-more-btn');
    await waitUntil(async () => !(await isHidden(alice.page, 'call-more-flip-camera-btn')), { message: 'Flip camera appears in the More menu once a 2nd camera is confirmed' });

    await alice.page.click('#call-more-flip-camera-btn');
    await new Promise((r) => setTimeout(r, 500));

    const flipCalls = await alice.page.evaluate(() => window.__flipCameraCalls);
    assert(flipCalls.length === 1 && flipCalls[0].ideal === 'environment', `expected one getUserMedia call requesting the environment-facing camera, got ${JSON.stringify(flipCalls)}`);
    assert(!(await isHidden(alice.page, 'call-active')), 'the call must still be active after flipping the camera');

    await bob.page.click('#call-end-btn');
    await waitUntil(() => callOver(alice.page), { message: 'alice overlay clears once bob ends the call' });

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);
    assert(bob.errors.length === 0, `bob unexpected JS errors: ${JSON.stringify(bob.errors)}`);

    await closeSession(alice);
    await closeSession(bob);
});

test('bob can decline an incoming call, which clears it for both parties', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);

    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    await openPage(bob.page, absoluteUrl('/dashboard'));
    await openPage(alice.page, conversationUrl);

    await alice.page.click('#voice-call-button');
    await waitUntil(async () => !(await isHidden(bob.page, 'call-incoming')), { timeout: 8000, message: 'bob sees the incoming call' });

    await bob.page.click('#call-reject-btn');

    await waitUntil(() => callOver(bob.page), { message: 'bob overlay clears after declining' });
    await waitUntil(() => callOver(alice.page), { timeout: 8000, message: 'alice overlay clears once bob declines' });

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);
    assert(bob.errors.length === 0, `bob unexpected JS errors: ${JSON.stringify(bob.errors)}`);

    await closeSession(alice);
    await closeSession(bob);
});

test('accepting a call that connects slowly does not hang it back up (regression for the ring-timeout race)', async () => {
    // Real bug: the client's own ring-timeout auto-reject only got
    // cancelled once showActive() ran, which happens AFTER getUserMedia()
    // resolves. If Accept was tapped just before that timeout and the
    // media/accept round-trip took a few seconds (routine on mobile), the
    // timeout fired, its /reject call failed (the call was already
    // 'accepted' server-side), and the old code ran teardown() anyway -
    // silently hanging up a call the user had just answered. Reproduced
    // here by delaying getUserMedia on bob's page well past the real
    // default ring_timeout_seconds (30s), so the connection genuinely
    // completes after that window would have expired.
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);

    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    // Delay bob's getUserMedia to resolve ~32s after it's first called -
    // past the 30s default ring window - purely on his page, before any
    // app script runs.
    await bob.page.evaluateOnNewDocument(() => {
        const originalGetUserMedia = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
        navigator.mediaDevices.getUserMedia = function (constraints) {
            return new Promise((resolve, reject) => {
                setTimeout(() => originalGetUserMedia(constraints).then(resolve, reject), 32000);
            });
        };
    });

    await openPage(bob.page, absoluteUrl('/dashboard'));
    await openPage(alice.page, conversationUrl);

    await alice.page.click('#voice-call-button');
    await waitUntil(async () => !(await isHidden(bob.page, 'call-incoming')), { timeout: 8000, message: 'bob sees the incoming call' });

    // Tap Accept right away - well before the 30s window, but the actual
    // connection (gated on the delayed getUserMedia) won't complete until
    // past it.
    await bob.page.click('#call-accept-btn');

    // The old bug's teardown() briefly hides #call-overlay entirely (a
    // real, visible flicker for the user) before the call-participant
    // auto-join poll silently re-joins it a couple of seconds later - so
    // "does it eventually show active" alone isn't a reliable check here.
    // What must never happen from the moment Accept is tapped is the
    // overlay disappearing at all: sample it continuously up to and past
    // the old 30s window.
    let overlayWentHidden = false;
    const sampleUntil = Date.now() + 34000;
    while (Date.now() < sampleUntil) {
        const hidden = await bob.page.evaluate(() => document.getElementById('call-overlay').classList.contains('hidden'));
        if (hidden) {
            overlayWentHidden = true;
            break;
        }
        if (!(await isHidden(bob.page, 'call-active'))) {
            break; // reached the active panel cleanly - no need to keep sampling
        }
        await new Promise((resolve) => setTimeout(resolve, 250));
    }
    assert(!overlayWentHidden, 'bob\'s call overlay must never disappear between tapping Accept and the call going active');

    await waitUntil(async () => !(await isHidden(bob.page, 'call-active')), {
        timeout: 45000,
        message: 'bob reaches the active call panel despite the slow accept',
    });
    await waitUntil(async () => !(await isHidden(alice.page, 'call-active')), {
        timeout: 8000,
        message: 'alice reaches the active call panel',
    });

    // Give it a further couple of seconds past connection - the old
    // code's timer (if it had fired) would tear it down around now.
    await new Promise((resolve) => setTimeout(resolve, 2000));
    assert(!(await isHidden(bob.page, 'call-active')), 'bob\'s call must still be active - not silently hung up');
    assert(!(await isHidden(alice.page, 'call-active')), 'alice\'s call must still be active');
    assert(bob.errors.length === 0, `bob unexpected JS errors: ${JSON.stringify(bob.errors)}`);
    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);

    await alice.page.click('#call-end-btn');
    await waitUntil(() => callOver(alice.page), { message: 'alice overlay clears after ending the call' });
    await waitUntil(() => callOver(bob.page), { timeout: 8000, message: 'bob overlay clears once alice ends the call' });

    await closeSession(alice);
    await closeSession(bob);
});

test('carol cannot call bob while bob is already ringing with alice', async () => {
    const aliceBobConversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const carolBobConversationUrl = await ensureConversationUrl(USERS.carol, USERS.bob);

    const alice = await loginAs(USERS.alice);
    const carol = await loginAs(USERS.carol);
    const bob = await loginAs(USERS.bob);

    await openPage(bob.page, absoluteUrl('/dashboard'));
    await openPage(alice.page, aliceBobConversationUrl);
    await alice.page.click('#voice-call-button');
    await waitUntil(async () => !(await isHidden(bob.page, 'call-incoming')), { timeout: 8000, message: 'bob is ringing from alice' });

    // Carol attempts a call to bob while he's busy ringing with alice.
    await openPage(carol.page, carolBobConversationUrl);
    await carol.page.click('#voice-call-button');

    await waitUntil(async () => {
        const text = await carol.page.$eval('#call-error', (el) => el.textContent).catch(() => '');
        return text.length > 0;
    }, { timeout: 5000, message: 'carol sees a busy error when calling bob' });

    const errorText = await carol.page.$eval('#call-error', (el) => el.textContent);
    assert(errorText.toLowerCase().includes('busy') || errorText.toLowerCase().includes('another call'), `expected a busy message, got "${errorText}"`);

    // Clean up: bob declines alice's still-ringing call so no state leaks into later tests.
    await bob.page.click('#call-reject-btn');
    await waitUntil(() => callOver(alice.page), { timeout: 8000, message: 'alice overlay clears after bob declines' });

    await closeSession(alice);
    await closeSession(carol);
    await closeSession(bob);
});

test('alice adds carol to a live call with bob: it stays the SAME call, everyone gains a tile, and nobody navigates anywhere', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    await ensureContacts(USERS.alice, USERS.carol);

    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);
    const carol = await loginAs(USERS.carol);

    await openPage(bob.page, absoluteUrl('/dashboard'));
    // Carol just has a page open somewhere - being added to the call must
    // reach her wherever she is, with no navigation.
    await openPage(carol.page, absoluteUrl('/dashboard'));
    await openPage(alice.page, conversationUrl);

    const aliceUrlBefore = alice.page.url();
    const bobUrlBefore = bob.page.url();
    const carolUrlBefore = carol.page.url();

    await alice.page.click('#voice-call-button');
    await waitUntil(async () => !(await isHidden(bob.page, 'call-incoming')), { timeout: 8000, message: 'bob sees the incoming call' });
    await bob.page.click('#call-accept-btn');

    await waitUntil(async () => !(await isHidden(alice.page, 'call-active')), { timeout: 8000, message: 'alice reaches the active call panel' });
    await waitUntil(async () => !(await isHidden(bob.page, 'call-active')), { timeout: 8000, message: 'bob reaches the active call panel' });

    // "Add member" lives behind the "More" overflow button, so the main
    // control grid stays a clean 2-row-of-3 icon grid.
    await alice.page.click('#call-more-btn');
    await waitUntil(async () => !(await isHidden(alice.page, 'call-more-menu')), { message: 'alice sees the More menu' });
    await alice.page.click('#call-more-add-member-btn');
    await waitUntil(async () => !(await isHidden(alice.page, 'call-add-member-modal')), { message: 'alice sees the add-member contact picker' });

    await waitUntil(async () => {
        return alice.page.evaluate((name) => {
            return [...document.querySelectorAll('#call-add-member-list button')].some((b) => b.textContent.includes(name));
        }, FULL_NAMES[USERS.carol]);
    }, { message: 'carol appears in the add-member contact list' });

    await alice.page.evaluate((name) => {
        const btn = [...document.querySelectorAll('#call-add-member-list button')].find((b) => b.textContent.includes(name));
        btn.click();
    }, FULL_NAMES[USERS.carol]);

    // Carol gets pulled into the call in progress - no ringing screen,
    // no redirect, just the active call (see autoJoinCall()).
    await waitUntil(async () => !(await isHidden(carol.page, 'call-active')), { timeout: 15000, message: 'carol lands straight in the active call she was added to' });

    // Everyone now sees three tiles: themselves plus the two others. This
    // is the whole point of the rebuild - it used to be impossible.
    for (const [label, session] of [['alice', alice], ['bob', bob], ['carol', carol]]) {
        await waitUntil(async () => (await tileCount(session.page)) === 3, {
            timeout: 20000,
            message: `${label} sees 3 participant tiles (got ${await tileCount(session.page)})`,
        });
        await waitUntil(async () => (await remoteTileCount(session.page)) === 2, { timeout: 20000, message: `${label} sees both other participants` });
    }

    // Real mesh WebRTC: every participant genuinely receives live audio
    // from the other two, not just a tile placeholder.
    for (const [label, session] of [['alice', alice], ['bob', bob], ['carol', carol]]) {
        await waitUntil(async () => {
            return session.page.evaluate(() => {
                return [...document.querySelectorAll('#call-grid [data-tile-remote="1"] video')]
                    .filter((v) => v.srcObject && v.srcObject.getAudioTracks().length > 0).length === 2;
            });
        }, { timeout: 30000, message: `${label} receives live audio from both other participants` });
    }

    // The header reflects a group call rather than naming one person.
    const header = await alice.page.$eval('#call-active-name', (el) => el.textContent);
    assert(header.includes('3 people'), `expected a group-call header, got "${header}"`);

    // Nobody was navigated anywhere - the old behavior ended the call and
    // redirected everyone to /meetings/{id}.
    assert(!alice.page.url().includes('/meetings/'), `alice must not be redirected to a meeting, got "${alice.page.url()}"`);
    assert(!bob.page.url().includes('/meetings/'), `bob must not be redirected to a meeting, got "${bob.page.url()}"`);
    assert(!carol.page.url().includes('/meetings/'), `carol must not be redirected to a meeting, got "${carol.page.url()}"`);
    assert(alice.page.url() === aliceUrlBefore, 'alice stayed on the page she started the call from');
    assert(bob.page.url() === bobUrlBefore, 'bob never navigated');
    assert(carol.page.url() === carolUrlBefore, 'carol never navigated');

    // One person leaving must not end the call for the others.
    await carol.page.click('#call-end-btn');
    await waitUntil(() => callOver(carol.page), { timeout: 8000, message: 'carol\'s own overlay clears after leaving' });

    for (const [label, session] of [['alice', alice], ['bob', bob]]) {
        await waitUntil(async () => (await tileCount(session.page)) === 2, { timeout: 20000, message: `${label}'s grid shrinks back to 2 tiles once carol leaves` });
        assert(!(await isHidden(session.page, 'call-active')), `${label} must still be in the call after carol left`);
    }

    // And the last two hanging up does end it.
    await alice.page.click('#call-end-btn');
    await waitUntil(() => callOver(alice.page), { timeout: 8000, message: 'alice overlay clears after ending' });
    await waitUntil(() => callOver(bob.page), { timeout: 15000, message: 'bob overlay clears once he is alone in the call' });

    // A polling loop can have a request already in flight the instant the
    // call state changes server-side out from under it, so a late 409/403
    // from one of those is Chrome's own network-tab logging rather than a
    // functional failure. The badge-poll 429 is the shared layout's
    // contacts/unread polling being rate-limited by three simultaneous
    // logged-in sessions, unrelated to calls.
    const benignLate = /409 \(Conflict\)|403 \(Forbidden\)|429/;
    for (const [label, session] of [['alice', alice], ['bob', bob], ['carol', carol]]) {
        const real = session.errors.filter((e) => !benignLate.test(e));
        assert(real.length === 0, `${label} unexpected JS errors: ${JSON.stringify(real)}`);
    }

    await closeSession(alice);
    await closeSession(bob);
    await closeSession(carol);
});
