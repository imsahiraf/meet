import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, absoluteUrl } from './support/session.js';
import { ensureConversationUrl, ensureContacts, FULL_NAMES } from './support/fixtures.js';

// Call features of the well-known call apps (2026-11-08): polls, questions, captions, voice to video,
// background blur, noise reduction, low data mode and call waiting.

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

function isHidden(page, id) {
    return page.evaluate((sel) => document.getElementById(sel).classList.contains('hidden'), id);
}

function callOver(page) {
    return page.evaluate(() => {
        if (!document.getElementById('call-ended').classList.contains('hidden')) document.getElementById('call-ended-close').click();
        return document.getElementById('call-overlay').classList.contains('hidden');
    });
}

async function openPage(page, url) {
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => !!window.MeetCalls && !!window.axios, { timeout: 20000 });
}

async function moreItem(page, id) {
    await page.click('#call-more-btn');
    await page.waitForFunction(() => !document.getElementById('call-more-menu').classList.contains('hidden'), { timeout: 4000 });
    return page.$eval('#' + id, (el) => ({ text: el.textContent.trim(), hidden: el.classList.contains('hidden') }));
}

async function pressMore(page, id) {
    await moreItem(page, id);
    await page.click('#' + id);
}

// a fake speech service: the test says what was "heard"
async function fakeSpeech(page) {
    await page.evaluateOnNewDocument(() => {
        const Fake = function () {
            window.__speech = this;
            this.start = () => {};
            this.stop = () => {};
        };
        window.SpeechRecognition = Fake;
        window.webkitSpeechRecognition = Fake;
    });
}

async function connectVoiceCall(caller, callee, conversationUrl) {
    await openPage(callee.page, absoluteUrl('/dashboard'));
    await openPage(caller.page, conversationUrl);
    await caller.page.click('#voice-call-button');
    await waitUntil(async () => !(await isHidden(callee.page, 'call-incoming')), { timeout: 8000, message: 'the call rings' });
    await callee.page.click('#call-accept-btn');
    await waitUntil(async () => !(await isHidden(caller.page, 'call-active')), { timeout: 8000, message: 'caller is in the call' });
    await waitUntil(async () => !(await isHidden(callee.page, 'call-active')), { timeout: 8000, message: 'callee is in the call' });
    await waitUntil(() => caller.page.evaluate(() => [...document.querySelectorAll('#call-grid [data-tile-remote="1"] video')]
        .some((v) => v.srcObject && v.srcObject.getAudioTracks().length)), { message: 'audio connects' });
}

test('in a call: polls, questions, captions, voice to video, blur, noise reduction and low data mode', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);
    await fakeSpeech(alice.page);
    await connectVoiceCall(alice, bob, conversationUrl);

    // poll: alice asks, bob gets a "Vote" note, votes, alice sees the vote
    await pressMore(alice.page, 'call-more-polls-btn');
    await alice.page.waitForFunction(() => !document.getElementById('call-polls-sheet').classList.contains('hidden'));
    await alice.page.type('#call-poll-q', 'Lunch at one?');
    const inputs = await alice.page.$$('#call-poll-options input');
    await inputs[0].type('Yes');
    await inputs[1].type('No');
    await alice.page.click('#call-poll-form button[type="submit"]');
    await waitUntil(async () => !(await isHidden(bob.page, 'call-toast')), { message: 'bob is told about the poll' });
    await bob.page.click('#call-toast-action');
    await bob.page.waitForFunction(() => document.querySelectorAll('#call-polls-list .call-poll-option').length === 2);
    await bob.page.click('#call-polls-list .call-poll-option');
    await waitUntil(() => alice.page.evaluate(() => document.getElementById('call-polls-list').textContent.includes('1 vote')), { message: 'alice sees bob\'s vote' });

    // question: bob asks, alice likes it, bob sees the like
    await bob.page.click('[data-polls-tab="questions"]');
    await bob.page.type('#call-question-input', 'Who brings the slides?');
    await bob.page.click('#call-question-form button[type="submit"]');
    await alice.page.click('[data-polls-tab="questions"]');
    await waitUntil(() => alice.page.evaluate(() => document.getElementById('call-questions-list').textContent.includes('Who brings the slides?')), { message: 'alice sees the question' });
    await alice.page.click('#call-questions-list button');
    await waitUntil(() => bob.page.evaluate(() => document.querySelector('#call-questions-list button').textContent.includes('1')), { message: 'bob sees the like' });
    await alice.page.click('#call-polls-close');
    await bob.page.click('#call-polls-close');

    // captions: alice's speech becomes text on bob's screen
    await pressMore(alice.page, 'call-more-captions-btn');
    assert((await moreItem(alice.page, 'call-more-captions-btn')).text === 'Captions: On', 'captions switch shows On');
    await alice.page.click('#call-more-btn');
    await alice.page.evaluate(() => {
        const result = [{ transcript: 'hello bob' }];
        result.isFinal = true;
        window.__speech.onresult({ resultIndex: 0, results: [result] });
    });
    await waitUntil(() => bob.page.evaluate(() => document.getElementById('call-caption').textContent === 'Browser Alice: hello bob'), { message: 'bob sees alice\'s caption' });

    // noise reduction: on by default, can be switched off
    assert((await moreItem(alice.page, 'call-more-noise-btn')).text === 'Noise reduction: On', 'noise reduction starts on');
    await alice.page.click('#call-more-noise-btn');
    await waitUntil(async () => (await moreItem(alice.page, 'call-more-noise-btn')).text === 'Noise reduction: Off', { message: 'noise reduction off' });
    await alice.page.click('#call-more-btn');

    // voice to video: bob sees alice's picture and is offered to turn on his own
    const videoItem = await moreItem(alice.page, 'call-more-video-btn');
    assert(!videoItem.hidden, 'a voice call offers "Turn on video"');
    await alice.page.click('#call-more-video-btn');
    await waitUntil(() => bob.page.evaluate(() => [...document.querySelectorAll('#call-grid [data-tile-remote="1"] .call-tile-video')]
        .some((v) => !v.classList.contains('hidden'))), { message: 'bob sees alice\'s video' });
    await waitUntil(() => bob.page.evaluate(() => document.getElementById('call-toast-text').textContent.includes('turned on video')), { message: 'bob is offered video' });
    assert(!(await isHidden(alice.page, 'call-camera-btn')), 'alice now has the camera button');

    // background blur: the outgoing picture becomes the processed one, and back
    await pressMore(alice.page, 'call-more-blur-btn');
    await waitUntil(() => alice.page.evaluate(() => {
        const v = document.querySelector('#call-grid [data-tile-remote="0"] video');
        const t = v && v.srcObject && v.srcObject.getVideoTracks()[0];
        return t && t.constructor.name === 'CanvasCaptureMediaStreamTrack';
    }), { timeout: 30000, message: 'alice sends the blurred picture' });
    assert((await moreItem(alice.page, 'call-more-blur-btn')).text === 'Background blur: On', 'blur switch shows On');
    await alice.page.click('#call-more-blur-btn');
    await waitUntil(() => alice.page.evaluate(() => {
        const t = document.querySelector('#call-grid [data-tile-remote="0"] video').srcObject.getVideoTracks()[0];
        return t.constructor.name !== 'CanvasCaptureMediaStreamTrack' && t.readyState === 'live';
    }), { message: 'blur off gives the camera back' });

    // low data mode
    await pressMore(bob.page, 'call-more-lowdata-btn');
    assert((await moreItem(bob.page, 'call-more-lowdata-btn')).text === 'Low data mode: On', 'low data switch shows On');
    await bob.page.click('#call-more-btn');

    await alice.page.click('#call-end-btn');
    await waitUntil(() => callOver(alice.page), { message: 'alice call over' });
    await waitUntil(() => callOver(bob.page), { timeout: 8000, message: 'bob call over' });

    assert(alice.errors.length === 0, `alice JS errors: ${JSON.stringify(alice.errors)}`);
    assert(bob.errors.length === 0, `bob JS errors: ${JSON.stringify(bob.errors)}`);
    await closeSession(alice);
    await closeSession(bob);
});

test('call waiting: carol calls bob while he talks to alice; bob ends the first call and answers carol', async () => {
    const aliceBob = await ensureConversationUrl(USERS.alice, USERS.bob);
    const carolBob = await ensureConversationUrl(USERS.carol, USERS.bob);
    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);
    const carol = await loginAs(USERS.carol);
    await connectVoiceCall(alice, bob, aliceBob);

    await openPage(carol.page, carolBob);
    await carol.page.click('#voice-call-button');
    await waitUntil(async () => !(await isHidden(bob.page, 'call-waiting')), { timeout: 10000, message: 'bob sees the waiting call' });
    const who = await bob.page.$eval('#call-waiting-name', (el) => el.textContent);
    assert(who === 'Browser Carol', `waiting call should name carol, got "${who}"`);

    await bob.page.click('#call-waiting-answer');
    await waitUntil(async () => !(await isHidden(carol.page, 'call-active')), { timeout: 10000, message: 'carol is connected to bob' });
    await waitUntil(() => callOver(alice.page), { timeout: 10000, message: 'alice\'s call ends' });
    const bobWith = await bob.page.$eval('#call-active-name', (el) => el.textContent);
    assert(bobWith === 'Browser Carol', `bob should now talk to carol, got "${bobWith}"`);

    await waitUntil(() => carol.page.evaluate(() => [...document.querySelectorAll('#call-grid [data-tile-remote="1"] video')]
        .some((v) => v.srcObject && v.srcObject.getAudioTracks().length)), { message: 'carol hears bob' });
    await carol.page.click('#call-end-btn');
    await waitUntil(() => callOver(carol.page), { message: 'carol call over' });
    await waitUntil(() => callOver(bob.page), { timeout: 8000, message: 'bob call over' });

    for (const s of [alice, bob, carol]) {
        assert(s.errors.length === 0, `JS errors: ${JSON.stringify(s.errors)}`);
        await closeSession(s);
    }
});

test('group call: pin a person to fill the screen, unpin, and switch between grid and speaker view', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    await ensureContacts(USERS.alice, USERS.carol);
    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);
    const carol = await loginAs(USERS.carol);
    await openPage(carol.page, absoluteUrl('/dashboard'));
    await connectVoiceCall(alice, bob, conversationUrl);

    await pressMore(alice.page, 'call-more-add-member-btn');
    await waitUntil(() => alice.page.evaluate((name) => [...document.querySelectorAll('#call-add-member-list button')].some((b) => b.textContent.includes(name)), FULL_NAMES[USERS.carol]), { message: 'carol in the picker' });
    await alice.page.evaluate((name) => [...document.querySelectorAll('#call-add-member-list button')].find((b) => b.textContent.includes(name)).click(), FULL_NAMES[USERS.carol]);
    await waitUntil(() => alice.page.evaluate(() => document.querySelectorAll('#call-grid .call-tile').length === 3), { timeout: 20000, message: 'three tiles' });

    // the pin button shows on group tiles; pinning bob puts him in the big spot
    const bobTile = await alice.page.$eval('#call-grid [data-tile-remote="1"]', (t) => t.dataset.tileUserId);
    await waitUntil(() => alice.page.evaluate((id) => getComputedStyle(document.querySelector('#call-tile-' + id + ' .call-tile-pin')).display !== 'none', bobTile), { message: 'pin button visible' });
    await alice.page.click('#call-tile-' + bobTile + ' .call-tile-pin');
    await waitUntil(() => alice.page.evaluate((id) => document.getElementById('call-grid').classList.contains('tiles-spotlight')
        && document.getElementById('call-tile-' + id).classList.contains('call-tile--spotlight'), bobTile), { message: 'bob pinned big' });
    await alice.page.click('#call-tile-' + bobTile + ' .call-tile-pin');
    await waitUntil(() => alice.page.evaluate(() => document.getElementById('call-grid').classList.contains('tiles-3')), { message: 'back to the grid' });

    // layout switch is offered in a group call
    const layout = await moreItem(alice.page, 'call-more-layout-btn');
    assert(!layout.hidden && layout.text.startsWith('Layout: grid'), `layout item: ${JSON.stringify(layout)}`);
    await alice.page.click('#call-more-layout-btn');
    assert((await moreItem(alice.page, 'call-more-layout-btn')).text.startsWith('Layout: speaker'), 'speaker view on');
    await alice.page.click('#call-more-btn');

    await waitUntil(async () => !(await isHidden(carol.page, 'call-active')), { message: 'carol is in the call' });
    await carol.page.click('#call-end-btn');
    await waitUntil(() => callOver(carol.page), { message: 'carol left' });
    for (const s of [alice, bob]) {
        await waitUntil(() => s.page.evaluate(() => document.querySelectorAll('#call-grid .call-tile').length === 2), { timeout: 20000, message: 'two tiles left' });
    }
    await alice.page.click('#call-end-btn');
    // a poll already in flight when somebody leaves can get a late 403 / 409 (same rule as the group test in calls.test.js)
    const benignLate = /409 \(Conflict\)|403 \(Forbidden\)|429/;
    for (const s of [alice, bob, carol]) {
        await waitUntil(() => callOver(s.page), { timeout: 15000, message: 'call over' });
        const real = s.errors.filter((e) => !benignLate.test(e));
        assert(real.length === 0, `JS errors: ${JSON.stringify(real)}`);
        await closeSession(s);
    }
});
