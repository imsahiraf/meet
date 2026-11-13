import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, absoluteUrl } from './support/session.js';
import { ensureConversationUrl, ensureContacts } from './support/fixtures.js';

async function waitUntil(fn, { timeout = 10000, interval = 300, message = 'condition' } = {}) {
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

test('tapping a call history row shows detail with a "Call back" option that starts a real new call', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);

    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    await openPage(bob.page, absoluteUrl('/dashboard'));
    await openPage(alice.page, conversationUrl);

    // Create a real "missed"-ish history entry: alice calls bob, bob declines.
    await alice.page.click('#voice-call-button');
    await waitUntil(async () => !(await isHidden(bob.page, 'call-incoming')), { timeout: 8000, message: 'bob sees the incoming call' });
    await bob.page.click('#call-reject-btn');
    await waitUntil(() => callOver(alice.page), { timeout: 8000, message: 'alice overlay clears after bob declines' });

    await openPage(alice.page, absoluteUrl('/calls/history'));

    const rowExists = await alice.page.evaluate(() => {
        return [...document.querySelectorAll('.call-history-row')].some((r) => r.dataset.otherName === 'Browser Bob');
    });
    assert(rowExists, 'expected a call history row for Browser Bob');

    assert(await isHidden(alice.page, 'call-history-detail-modal'), 'detail modal should start closed');

    await alice.page.evaluate(() => {
        const row = [...document.querySelectorAll('.call-history-row')].find((r) => r.dataset.otherName === 'Browser Bob');
        row.click();
    });

    assert(!(await isHidden(alice.page, 'call-history-detail-modal')), 'expected the detail modal to open after clicking a row');
    const detailName = await alice.page.$eval('#call-history-detail-name', (el) => el.textContent);
    assert(detailName === 'Browser Bob', `expected the detail modal to show "Browser Bob", got "${detailName}"`);

    // "Call back" reuses window.MeetCalls.start() - should place a real new call.
    await alice.page.click('#call-history-detail-voice');
    assert(await isHidden(alice.page, 'call-history-detail-modal'), 'expected the detail modal to close after tapping Call back');

    await waitUntil(async () => !(await isHidden(alice.page, 'call-outgoing')), { timeout: 8000, message: 'alice sees the outgoing call panel after calling back' });
    const outgoingName = await alice.page.$eval('#call-outgoing-name', (el) => el.textContent);
    assert(outgoingName === 'Browser Bob', `expected the outgoing call to target Browser Bob, got "${outgoingName}"`);

    await waitUntil(async () => !(await isHidden(bob.page, 'call-incoming')), { timeout: 8000, message: 'bob receives the real call-back' });

    await bob.page.click('#call-reject-btn');
    await waitUntil(() => callOver(alice.page), { timeout: 8000, message: 'alice overlay clears after the call-back is declined too' });

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);
    assert(bob.errors.length === 0, `bob unexpected JS errors: ${JSON.stringify(bob.errors)}`);

    await closeSession(alice);
    await closeSession(bob);
});

test('tapping "Call back" on a missed-call push notification (no page open yet) auto-starts the call once a page loads', async () => {
    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    await openPage(bob.page, absoluteUrl('/dashboard'));

    await ensureContacts(USERS.alice, USERS.bob);
    const bobId = await alice.page.evaluate(() => axios.get('/api/v1/contacts').then((r) => {
        const contact = r.data.data.find((c) => c.user.full_name === 'Browser Bob');
        return contact ? contact.contact_user_id : null;
    }));
    assert(bobId, 'expected to find Browser Bob\'s contact_user_id via the contacts API');

    // Simulates what public/service-worker.js's notificationclick
    // handler does for the "Call back" action on a missed-call
    // notification: open/focus a client window with these query
    // params, since there's no page open yet to call
    // window.MeetCalls.start() from directly.
    await openPage(alice.page, absoluteUrl('/dashboard?callback_user=' + bobId + '&callback_type=voice&callback_name=' + encodeURIComponent('Browser Bob')));

    await waitUntil(async () => !(await isHidden(alice.page, 'call-outgoing')), { timeout: 8000, message: 'alice auto-starts the call from the callback_user param' });
    const outgoingName = await alice.page.$eval('#call-outgoing-name', (el) => el.textContent);
    assert(outgoingName === 'Browser Bob', `expected the callback to target Browser Bob, got "${outgoingName}"`);

    // The query params must not linger (a refresh shouldn't re-trigger the call).
    assert(!alice.page.url().includes('callback_user'), `expected the callback_user param to be stripped from the URL, got "${alice.page.url()}"`);

    await waitUntil(async () => !(await isHidden(bob.page, 'call-incoming')), { timeout: 8000, message: 'bob receives the auto-started callback call' });
    await bob.page.click('#call-reject-btn');
    await waitUntil(() => callOver(alice.page), { timeout: 8000, message: 'alice overlay clears' });

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);
    assert(bob.errors.length === 0, `bob unexpected JS errors: ${JSON.stringify(bob.errors)}`);

    await closeSession(alice);
    await closeSession(bob);
});
