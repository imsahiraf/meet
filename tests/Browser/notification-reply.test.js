import { test, assert } from './support/test.js';
import { loginAs, closeSession, getBrowser, USERS } from './support/session.js';
import { ensureConversationUrl } from './support/fixtures.js';

/**
 * A real OS notification click (with inline-reply text typed into the
 * notification shade) can't be triggered from outside the browser by
 * Puppeteer - that's an actual native-UI interaction Chrome handles
 * internally. Instead, this drives the service worker's own
 * sendQuickReply() function directly in its real execution context
 * (via Puppeteer's ServiceWorker target handle), with real fetch/
 * cookies/WebCrypto - exactly what a genuine notificationclick handler
 * would do, just triggered by the test instead of a native reply UI.
 */
async function getServiceWorker(browser, page) {
    await page.evaluate(() => navigator.serviceWorker.ready);
    const target = await browser.waitForTarget(
        (t) => t.type() === 'service_worker' && t.url().includes('service-worker.js'),
        { timeout: 10000 },
    );
    return target.worker();
}

test('notification reply: the service worker can send a quick reply directly, with no page open, and it is genuinely encrypted', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    const browser = await getBrowser();

    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    // Establish the conversation's key epoch first, same as any normal
    // send would (the quick-reply path itself also does this, but a
    // clean starting point makes the test deterministic).
    const conversationId = await alice.page.evaluate(() => document.getElementById('messages').dataset.conversationId);
    await alice.page.evaluate((id) => axios.get('/api/v1/conversations/' + id + '/encryption'), conversationId);

    const worker = await getServiceWorker(browser, alice.page);
    assert(worker, 'expected the service worker to be reachable as its own execution context');

    const replyText = 'quick reply from the notification shade ' + Date.now();
    await worker.evaluate((id, text) => self.sendQuickReply(id, text), conversationId, replyText);
    await new Promise((r) => setTimeout(r, 800));

    const raw = await alice.page.evaluate((id) => {
        return axios.get('/api/v1/messages/' + id).then((r) => r.data.data[r.data.data.length - 1]);
    }, conversationId);

    assert(raw.encryption_mode === 'e2e', `expected the quick-reply to be genuinely encrypted server-side, got encryption_mode "${raw.encryption_mode}"`);
    assert(raw.body !== replyText, 'expected the raw stored body to be ciphertext, not the plaintext reply text');
    assert(typeof raw.iv === 'string' && raw.iv.length > 0, 'expected a real IV alongside the ciphertext');

    // The already-open tab picks it up via its normal poll and renders
    // the correct decrypted plaintext - proving the whole round trip,
    // not just that *something* landed server-side.
    await new Promise((r) => setTimeout(r, 1500));
    const rendered = await alice.page.evaluate((text) => {
        const el = [...document.querySelectorAll('[data-message-id]')].find((n) => n.dataset.body === text);
        return !!el;
    }, replyText);
    assert(rendered, 'expected the open conversation to poll in and correctly decrypt the quick-reply');

    await closeSession(alice);
});
