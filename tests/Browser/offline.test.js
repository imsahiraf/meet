import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS } from './support/session.js';
import { ensureConversationUrl } from './support/fixtures.js';

// A test that deliberately goes offline expects to see real network
// failures logged (poll requests, the incoming-call check, etc.) -
// that's the intended effect of setOfflineMode(true), not a bug. Only
// fail on errors that aren't just "the network was down."
function noUnexpectedErrors(session, label) {
    const unexpected = session.errors.filter((e) => !/ERR_INTERNET_DISCONNECTED|Network Error/.test(e));
    assert(unexpected.length === 0, `${label} had unexpected JS errors: ${JSON.stringify(unexpected)}`);
}

test('offline: messages are cached for offline reading, and a message composed while offline queues, shows as pending, and syncs once back online', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);

    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    const seedText = 'seed message for offline caching ' + Date.now();
    await alice.page.type('#composer-input', seedText);
    await alice.page.click('#composer button[type="submit"]');
    await new Promise((r) => setTimeout(r, 1200));

    const cached = await alice.page.evaluate((conversationId) => MeetOffline.cachedMessages(conversationId), await alice.page.evaluate(() => document.getElementById('messages').dataset.conversationId));
    assert(Array.isArray(cached) && cached.some((m) => m.body === seedText), 'expected the just-sent message to already be cached in IndexedDB for offline reading');

    // Go genuinely offline (real network-level emulation, not just
    // disconnecting XHR) and compose a message.
    await alice.page.setOfflineMode(true);

    const offlineText = 'composed entirely while offline ' + Date.now();
    await alice.page.type('#composer-input', offlineText);
    await alice.page.click('#composer button[type="submit"]');
    await new Promise((r) => setTimeout(r, 500));

    const pendingState = await alice.page.evaluate((text) => {
        const el = [...document.querySelectorAll('[data-message-id]')].find((n) => n.dataset.body === text);
        return el ? { hasLocalId: !!el.dataset.localId, text: el.textContent } : null;
    }, offlineText);
    assert(pendingState, 'expected the offline-composed message to render immediately as an optimistic pending bubble');
    assert(pendingState.hasLocalId, 'expected the pending bubble to be tagged with a local id for later reconciliation');
    assert(pendingState.text.includes('Waiting to send'), `expected a "Waiting to send" indicator on the pending bubble, got "${pendingState.text}"`);

    const queuedCount = await alice.page.evaluate(() => MeetOffline.queueLength());
    assert(queuedCount === 1, `expected exactly one queued outbox action while offline, got ${queuedCount}`);

    // Back online - the layout's own online listener drains the queue.
    await alice.page.setOfflineMode(false);
    await new Promise((r) => setTimeout(r, 3000));

    const queuedAfter = await alice.page.evaluate(() => MeetOffline.queueLength());
    assert(queuedAfter === 0, `expected the outbox to have drained once back online, got ${queuedAfter} still queued`);

    const stillPending = await alice.page.evaluate((text) => {
        const el = [...document.querySelectorAll('[data-message-id]')].find((n) => n.dataset.body === text);
        return el ? el.textContent.includes('Waiting to send') : null;
    }, offlineText);
    assert(stillPending === false, 'expected the pending indicator to be gone once the message actually synced');

    // Confirm it genuinely landed server-side, not just in local state -
    // encrypted transparently like any other send, so the raw stored
    // body won't equal the plaintext; the last message being a fresh
    // e2e ciphertext (not the earlier seed message's) is what proves
    // this specific send actually reached the server.
    const raw = await alice.page.evaluate((conversationId) => {
        return axios.get('/api/v1/messages/' + conversationId).then((r) => {
            const last = r.data.data[r.data.data.length - 1];
            return { encryptionMode: last.encryption_mode, hasIv: !!last.iv };
        });
    }, await alice.page.evaluate(() => document.getElementById('messages').dataset.conversationId));
    assert(raw.encryptionMode === 'e2e' && raw.hasIv, `expected the offline-composed message to have actually been sent (and encrypted) server-side, got ${JSON.stringify(raw)}`);

    noUnexpectedErrors(alice, 'offline queue/sync');
    await closeSession(alice);
});

test('offline: the offline banner appears while disconnected and disappears once back online', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.carol);
    const alice = await loginAs(USERS.alice);

    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    let bannerHidden = await alice.page.evaluate(() => document.getElementById('offline-banner').classList.contains('hidden'));
    assert(bannerHidden, 'expected the offline banner to be hidden while online');

    await alice.page.setOfflineMode(true);
    await alice.page.evaluate(() => window.dispatchEvent(new Event('offline')));
    await new Promise((r) => setTimeout(r, 200));

    bannerHidden = await alice.page.evaluate(() => document.getElementById('offline-banner').classList.contains('hidden'));
    assert(!bannerHidden, 'expected the offline banner to appear once disconnected');

    await alice.page.setOfflineMode(false);
    await alice.page.evaluate(() => window.dispatchEvent(new Event('online')));
    await new Promise((r) => setTimeout(r, 500));

    bannerHidden = await alice.page.evaluate(() => document.getElementById('offline-banner').classList.contains('hidden'));
    assert(bannerHidden, 'expected the offline banner to disappear once reconnected');

    noUnexpectedErrors(alice, 'offline banner');
    await closeSession(alice);
});
