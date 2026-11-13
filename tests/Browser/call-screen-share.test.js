import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, absoluteUrl } from './support/session.js';
import { ensureConversationUrl } from './support/fixtures.js';

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

// The call screen is a participant grid now - "the video area" is a
// per-participant tile, not one shared wrapper.
function remoteTileShowsVideo(page) {
    return page.evaluate(() => {
        return [...document.querySelectorAll('#call-grid [data-tile-remote="1"]')].some(
            (tile) => !tile.querySelector('.call-tile-video').classList.contains('hidden'),
        );
    });
}

function selfTileShowsVideo(page) {
    return page.evaluate(() => {
        const tile = document.querySelector('#call-grid [data-tile-remote="0"]');
        return !!tile && !tile.querySelector('.call-tile-video').classList.contains('hidden');
    });
}

test('sharing your screen during a voice call shows real video to the other party without ever turning on your own camera, and stopping reverts them to the avatar view', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);

    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    await openPage(bob.page, absoluteUrl('/dashboard'));
    await openPage(alice.page, conversationUrl);

    await alice.page.click('#voice-call-button');
    await waitUntil(async () => !(await isHidden(bob.page, 'call-incoming')), { timeout: 8000, message: 'bob sees the incoming call' });
    await bob.page.click('#call-accept-btn');

    await waitUntil(async () => !(await isHidden(alice.page, 'call-active')), { timeout: 8000, message: 'alice reaches the active call panel' });
    await waitUntil(async () => !(await isHidden(bob.page, 'call-active')), { timeout: 8000, message: 'bob reaches the active call panel' });

    // No tile should be showing video yet - this is a voice call.
    assert(!(await remoteTileShowsVideo(alice.page)), 'alice should not see video in bob\'s tile before anyone shares');
    assert(!(await remoteTileShowsVideo(bob.page)), 'bob should not see video in alice\'s tile before anyone shares');
    assert(!(await selfTileShowsVideo(alice.page)), 'alice\'s own tile should show her avatar, not video, on a voice call');

    await pressShare(alice.page);

    await waitUntil(async () => !(await isHidden(alice.page, 'call-screen-sharing-badge')), { message: 'alice sees her own "Sharing your screen" badge' });

    await waitUntil(() => remoteTileShowsVideo(bob.page), { timeout: 10000, message: 'bob\'s tile for alice reveals video once the screen share track unmutes' });
    const aliceTileShowsAvatar = await bob.page.evaluate(() => {
        const tile = document.querySelector('#call-grid [data-tile-remote="1"]');
        return tile.querySelector('.call-tile-avatar').classList.contains('hidden');
    });
    assert(aliceTileShowsAvatar, 'the avatar in alice\'s tile should be replaced by her shared screen');

    // The badge arrives via the signal poll (~1s interval) independently
    // of the video area's own reveal (driven by the WebRTC track's
    // unmute event) - it can lag behind by a poll cycle or two.
    await waitUntil(async () => {
        const text = await bob.page.$eval('#call-remote-sharing-badge', (el) => el.textContent).catch(() => '');
        return text.includes('sharing their screen');
    }, { timeout: 8000, message: 'bob sees a "sharing their screen" label' });

    const bobHasVideoTrack = await bob.page.evaluate(() => {
        const video = document.querySelector('#call-grid [data-tile-remote="1"] video');
        return !!(video.srcObject && video.srcObject.getVideoTracks().length > 0);
    });
    assert(bobHasVideoTrack, 'expected bob to actually receive a real video track from alice\'s screen share');

    // Alice's own camera must never turn on - only the shared screen
    // becomes the outgoing video, which is what her own tile now
    // previews (alongside the "Sharing your screen" badge).
    const aliceOwnTrackIsScreenNotCamera = await alice.page.evaluate(() => {
        const tile = document.querySelector('#call-grid [data-tile-remote="0"]');
        const stream = tile.querySelector('video').srcObject;
        if (!stream) return false;
        // The camera was never requested for a voice call, so the only
        // video track that can exist here is the shared screen.
        return stream.getVideoTracks().length === 1;
    });
    assert(aliceOwnTrackIsScreenNotCamera, 'expected alice\'s own tile to preview the shared screen and no camera track');

    // ---- Stop sharing ----
    await pressShare(alice.page);
    await waitUntil(async () => await isHidden(alice.page, 'call-screen-sharing-badge'), { message: 'alice\'s own sharing badge clears after stopping' });
    await waitUntil(async () => await isHidden(bob.page, 'call-remote-sharing-badge'), { timeout: 8000, message: 'bob\'s "sharing their screen" badge clears once alice stops' });
    await waitUntil(async () => !(await remoteTileShowsVideo(bob.page)), { timeout: 8000, message: 'bob\'s tile for alice falls back to her avatar once sharing stops' });

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);
    assert(bob.errors.length === 0, `bob unexpected JS errors: ${JSON.stringify(bob.errors)}`);

    await alice.page.click('#call-end-btn');
    await waitUntil(() => callOver(alice.page), { message: 'alice overlay clears after ending the call' });

    await closeSession(alice);
    await closeSession(bob);
});
