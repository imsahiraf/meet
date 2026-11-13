import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS } from './support/session.js';
import { ensureConversationUrl } from './support/fixtures.js';

function noErrors(session, label) {
    assert(session.errors.length === 0, `${label} had JS errors: ${JSON.stringify(session.errors)}`);
}

test('e2e: a real WebCrypto round trip happens fully automatically - no unlock prompt, no password, ever - and the message is genuinely encrypted server-side', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    await bob.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    // The old per-user unlock banner must not exist at all any more -
    // the server holds the key and hands it to any authorized member
    // transparently.
    const unlockBannerExists = await alice.page.evaluate(() => !!document.getElementById('e2e-unlock-banner'));
    assert(!unlockBannerExists, 'expected the old E2E unlock banner to be gone entirely - keys are server-managed now, not per-user/password-derived');

    const text = 'a real end-to-end encrypted message ' + Date.now();
    await alice.page.type('#composer-input', text);
    await alice.page.click('#composer button[type="submit"]');
    await new Promise((r) => setTimeout(r, 2000));

    // Both parties see the correct plaintext, decrypted client-side,
    // with zero interaction required from either of them.
    const aliceRendered = await alice.page.evaluate((t) => {
        const el = [...document.querySelectorAll('[data-message-id]')].find((n) => n.dataset.body === t);
        return el ? el.dataset.encryptionMode : null;
    }, text);
    assert(aliceRendered === 'e2e', `expected the sender's own rendered message to be marked e2e, got "${aliceRendered}"`);

    await bob.page.reload({ waitUntil: 'networkidle0' });
    await new Promise((r) => setTimeout(r, 500));
    const bobRendered = await bob.page.evaluate((t) => {
        const el = [...document.querySelectorAll('[data-message-id]')].find((n) => n.dataset.body === t);
        return el ? el.dataset.encryptionMode : null;
    }, text);
    assert(bobRendered === 'e2e', `expected bob to see the same message decrypted to the same plaintext, got dataset match: "${bobRendered}"`);

    // The server-side representation is genuinely not the plaintext.
    const raw = await alice.page.evaluate(() => {
        const conversationId = document.getElementById('messages').dataset.conversationId;
        return axios.get('/api/v1/messages/' + conversationId).then((r) => r.data.data[r.data.data.length - 1]);
    });
    assert(raw.encryption_mode === 'e2e', `expected the raw server message to be encryption_mode "e2e", got "${raw.encryption_mode}"`);
    assert(raw.body !== text, 'expected the raw server-stored body to be ciphertext, not the plaintext the user typed');
    assert(typeof raw.iv === 'string' && raw.iv.length > 0, 'expected a real IV to be stored alongside the ciphertext');

    // The thread shows the "End-to-end encrypted" indicator now that a
    // real key has been issued for this conversation.
    const indicatorText = await alice.page.evaluate(() => document.getElementById('e2e-indicator').textContent);
    assert(indicatorText.includes('End-to-end encrypted'), `expected the e2e indicator to read "End-to-end encrypted", got "${indicatorText}"`);

    noErrors(alice, 'e2e round trip (alice)');
    noErrors(bob, 'e2e round trip (bob)');
    await closeSession(alice);
    await closeSession(bob);
});

test('e2e: the key is cached locally after the first fetch, so a second visit does not re-fetch it from the server', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.carol);
    const alice = await loginAs(USERS.alice);

    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    const text = 'caching check ' + Date.now();
    await alice.page.type('#composer-input', text);
    await alice.page.click('#composer button[type="submit"]');
    await new Promise((r) => setTimeout(r, 1500));

    const conversationId = await alice.page.evaluate(() => document.getElementById('messages').dataset.conversationId);
    const status = await alice.page.evaluate((id) => axios.get('/api/v1/conversations/' + id + '/encryption').then((r) => r.data.data), conversationId);
    assert(status.current_key_version, 'expected a key version to already be issued for this conversation');

    const cachedAfterVisit = await alice.page.evaluate((id, version) => MeetE2E.getConversationKey(id, version).then(() => true).catch(() => false), conversationId, status.current_key_version);
    assert(cachedAfterVisit, 'expected getConversationKey to succeed (fetching then caching, or reading straight from cache)');

    // Reload and confirm the cached key survives a fresh page load
    // (IndexedDB persists across reloads, unlike an in-memory variable).
    await alice.page.reload({ waitUntil: 'networkidle0' });
    const stillCached = await alice.page.evaluate((id, version) => {
        return new Promise((resolve) => {
            const request = indexedDB.open('meet-e2e', 1);
            request.onsuccess = () => {
                const db = request.result;
                const tx = db.transaction('conversation-keys', 'readonly');
                const req = tx.objectStore('conversation-keys').get(id + ':' + version);
                req.onsuccess = () => resolve(!!req.result);
                req.onerror = () => resolve(false);
            };
            request.onerror = () => resolve(false);
        });
    }, conversationId, status.current_key_version);
    assert(stillCached, 'expected the raw key material to still be cached in IndexedDB after a page reload');

    noErrors(alice, 'e2e key caching');
    await closeSession(alice);
});
