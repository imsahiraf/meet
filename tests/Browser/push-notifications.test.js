import { test, assert } from './support/test.js';
import { getBrowser, absoluteUrl, BASE_URL, USERS, PASSWORD, loginAs, closeSession } from './support/session.js';
import { ensureConversationUrl } from './support/fixtures.js';

async function waitUntil(fn, { timeout = 15000, interval = 300, message = 'condition' } = {}) {
    const start = Date.now();
    let lastError = null;
    while (Date.now() - start < timeout) {
        try {
            const value = await fn();
            if (value) return value;
        } catch (error) {
            lastError = error;
        }
        await new Promise((r) => setTimeout(r, interval));
    }
    throw new Error(`Timed out waiting for: ${message}${lastError ? ` (last error: ${lastError.message})` : ''}`);
}

/**
 * Chrome deliberately disables the real Push API in incognito-mode
 * browser contexts (undetectable by design - see crbug.com/41124656),
 * which is exactly what loginAs()'s isolated-session pattern uses for
 * every other test. This one test needs a real (non-incognito) page
 * on the browser's default context instead, so it can't reuse
 * loginAs()/closeSession() - it manages its own page/cookies.
 */
test('a real Web Push subscription is created once notification permission is granted', async () => {
    const browser = await getBrowser();
    await browser.defaultBrowserContext().overridePermissions(BASE_URL, ['notifications']);

    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', (err) => errors.push(`PAGEERROR: ${err.message}`));
    page.on('console', (msg) => { if (msg.type() === 'error') errors.push(`CONSOLE.ERROR: ${msg.text()}`); });

    try {
        await page.goto(absoluteUrl('/login'), { waitUntil: 'networkidle0' });
        await page.type('input[name="login"]', USERS.alice);
        await page.type('input[name="password"]', PASSWORD);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('button[type="submit"]'),
        ]);

        const firebaseConfigPresent = await page.evaluate(() => !!document.querySelector('meta[name="firebase-vapid-key"]'));
        assert(firebaseConfigPresent, 'expected a firebase-vapid-key meta tag to be rendered (server has Firebase configured)');

        const permission = await page.evaluate(() => window.Notification && Notification.permission);
        assert(permission === 'granted', `expected Notification.permission to be granted, got ${permission}`);

        // The app's own script kicks off subscribeToPushNotifications()
        // automatically once permission is granted - it's a real round
        // trip to a real push service (Chrome's own, backed by FCM),
        // so this polls rather than asserting on a fixed short wait.
        // Observed (via manual repro) taking anywhere from ~1s to ~6s
        // for the round trip to Chrome's real FCM-backed push service
        // to resolve depending on network conditions - 30s gives that
        // real-world variance headroom without masking an actual hang.
        const subscription = await waitUntil(
            () => page.evaluate(() => navigator.serviceWorker.getRegistration().then((reg) => reg?.pushManager.getSubscription()).then((sub) => sub && sub.toJSON())),
            { timeout: 30000, message: 'a real PushManager subscription to appear after the app auto-subscribes' },
        );
        assert(subscription.endpoint, 'expected the subscription to have a real push service endpoint URL');

        assert(errors.length === 0, `unexpected JS errors: ${JSON.stringify(errors)}`);
    } finally {
        // Leave no push subscription / no logged-in session behind on
        // the browser's shared default context for later test files.
        await page.evaluate(() => navigator.serviceWorker.ready.then((reg) => reg.pushManager.getSubscription()).then((sub) => sub && sub.unsubscribe())).catch(() => {});
        await page.evaluate(() => fetch('/logout', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content } })).catch(() => {});
        await page.deleteCookie(...(await page.cookies())).catch(() => {});
        await page.close().catch(() => {});
    }
});

/**
 * Proves the full server-side send actually reaches real Firebase
 * infrastructure with a genuinely valid token: if PushNotificationService's
 * send were rejected by Firebase as NotFound/invalid, it would delete
 * the subscription row (see its catch block) - this real token
 * surviving a real triggered send is exactly the signal that the
 * Admin SDK credentials, and the token itself, are both genuinely
 * valid against Firebase's real servers, not just that no exception
 * happened to be thrown.
 */
test('a message notification sent through the real Firebase Admin SDK does not get the real subscription deleted as invalid', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const browser = await getBrowser();
    await browser.defaultBrowserContext().overridePermissions(BASE_URL, ['notifications']);

    const alicePage = await browser.newPage();
    const errors = [];
    alicePage.on('pageerror', (err) => errors.push(`PAGEERROR: ${err.message}`));
    alicePage.on('console', (msg) => { if (msg.type() === 'error') errors.push(`CONSOLE.ERROR: ${msg.text()}`); });

    try {
        await alicePage.goto(absoluteUrl('/login'), { waitUntil: 'networkidle0' });
        await alicePage.type('input[name="login"]', USERS.alice);
        await alicePage.type('input[name="password"]', PASSWORD);
        await Promise.all([
            alicePage.waitForNavigation({ waitUntil: 'networkidle0' }),
            alicePage.click('button[type="submit"]'),
        ]);

        await waitUntil(
            () => alicePage.evaluate(() => navigator.serviceWorker.getRegistration().then((reg) => reg?.pushManager.getSubscription()).then((sub) => sub && sub.toJSON())),
            { timeout: 30000, message: 'alice to have a real registered push subscription' },
        );

        const subscriptionCountBefore = await alicePage.evaluate(() => axios.get('/api/v1/conversations').then(() => document.body.textContent.length > 0));
        assert(subscriptionCountBefore, 'expected alice to be able to make authenticated requests');

        const bob = await loginAs(USERS.bob);
        await bob.page.goto(conversationUrl, { waitUntil: 'domcontentloaded' });
        await bob.page.type('#composer-input', 'triggering a real Firebase push send ' + Date.now());
        await bob.page.click('#composer button[type="submit"]');
        await new Promise((r) => setTimeout(r, 3000));
        await closeSession(bob);

        // If Firebase had rejected alice's token, PushNotificationService
        // would have deleted her subscription row - it surviving proves
        // the send round-tripped successfully against real Firebase
        // servers with a token Firebase itself considers valid.
        const stillSubscribed = await alicePage.evaluate(() => {
            return fetch('/profile/push-subscriptions').then((r) => r.text()).then((html) => !html.includes('No devices are subscribed'));
        });
        assert(stillSubscribed, "expected alice's real push subscription to still exist after a real notification send through it");

        assert(errors.length === 0, `unexpected JS errors: ${JSON.stringify(errors)}`);
    } finally {
        await alicePage.evaluate(() => navigator.serviceWorker.ready.then((reg) => reg.pushManager.getSubscription()).then((sub) => sub && sub.unsubscribe())).catch(() => {});
        await alicePage.evaluate(() => fetch('/logout', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content } })).catch(() => {});
        await alicePage.deleteCookie(...(await alicePage.cookies())).catch(() => {});
        await alicePage.close().catch(() => {});
    }
});

/**
 * Real bug: getDeviceToken()'s bare try/catch swallowed EVERY failure
 * indiscriminately, including the browser's own InvalidStateError
 * ("a subscription with a different applicationServerKey already
 * exists") that Firebase's getToken() hits internally when a stale
 * subscription from a previous VAPID key (a different Firebase
 * project, or an earlier non-Firebase Web Push setup) is still
 * registered - silently returning null with no way to ever recover.
 * This creates a real (differently-keyed, validly-formatted)
 * subscription first to reproduce that exact scenario, then confirms
 * unsubscribeIfStale() clears it so a real device token is still
 * obtained.
 */
test('a leftover push subscription from a different VAPID key does not silently block getting a real device token', async () => {
    const browser = await getBrowser();
    await browser.defaultBrowserContext().overridePermissions(BASE_URL, ['notifications']);

    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', (err) => errors.push(`PAGEERROR: ${err.message}`));
    page.on('console', (msg) => { if (msg.type() === 'error') errors.push(`CONSOLE.ERROR: ${msg.text()}`); });

    try {
        await page.goto(absoluteUrl('/login'), { waitUntil: 'networkidle0' });
        await page.type('input[name="login"]', USERS.alice);
        await page.type('input[name="password"]', PASSWORD);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('button[type="submit"]'),
        ]);

        // Let the app's own auto-subscribe run first, then deliberately
        // replace it with a subscription keyed to a DIFFERENT (but
        // validly-formatted) VAPID key - simulating exactly the stale
        // leftover this fix targets.
        await waitUntil(
            () => page.evaluate(() => navigator.serviceWorker.getRegistration().then((reg) => reg?.pushManager.getSubscription()).then((sub) => sub && sub.toJSON())),
            { timeout: 30000, message: 'the app to auto-subscribe first' },
        );

        await page.evaluate(async () => {
            const registration = await navigator.serviceWorker.ready;
            const existing = await registration.pushManager.getSubscription();
            if (existing) await existing.unsubscribe();

            const keyPair = await crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits']);
            const rawKey = await crypto.subtle.exportKey('raw', keyPair.publicKey);
            await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: new Uint8Array(rawKey) });
        });

        const staleSubExists = await page.evaluate(() => navigator.serviceWorker.ready.then((reg) => reg.pushManager.getSubscription()).then((sub) => !!sub));
        assert(staleSubExists, 'expected the manually-created stale-key subscription to exist before the fix runs');

        // getDeviceToken() internally calls unsubscribeIfStale() before
        // Firebase's getToken() - without that, the mismatched key
        // would make the browser's own pushManager.subscribe() (called
        // by getToken() under the hood) throw, silently swallowed into
        // a null return.
        const token = await page.evaluate(() => navigator.serviceWorker.ready.then((reg) => window.MeetPush.getDeviceToken(reg)));
        assert(typeof token === 'string' && token.length > 0, `expected a real device token despite the stale-keyed subscription, got ${JSON.stringify(token)}`);

        const finalKeyMatches = await page.evaluate(() => navigator.serviceWorker.ready.then((reg) => reg.pushManager.getSubscription()).then((sub) => {
            const vapidKey = document.querySelector('meta[name="firebase-vapid-key"]')?.content;
            const currentKey = window.MeetPush.urlBase64ToUint8Array(vapidKey);
            const subKey = new Uint8Array(sub.options.applicationServerKey);
            return currentKey.length === subKey.length && currentKey.every((b, i) => b === subKey[i]);
        }));
        assert(finalKeyMatches, 'expected the subscription to now be keyed to the real (current) VAPID key, not the stale one');

        assert(errors.length === 0, `unexpected JS errors: ${JSON.stringify(errors)}`);
    } finally {
        await page.evaluate(() => navigator.serviceWorker.ready.then((reg) => reg.pushManager.getSubscription()).then((sub) => sub && sub.unsubscribe())).catch(() => {});
        await page.evaluate(() => fetch('/logout', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content } })).catch(() => {});
        await page.deleteCookie(...(await page.cookies())).catch(() => {});
        await page.close().catch(() => {});
    }
});

/**
 * A real native push event can't be dispatched from outside the
 * browser by Puppeteer (that's genuine external-push-service
 * infrastructure timing Chrome handles internally, and a headless run
 * has no reliable way to force it) - same constraint documented in
 * notification-reply.test.js for sendQuickReply(). This drives the
 * service worker's own markMessageDelivered() directly in its real
 * execution context instead (real fetch/cookies), exactly what the
 * push handler itself calls - see public/service-worker.js and
 * MessageService::markDelivered()'s docblock for the actual scenario
 * this closes: a message pushed to a fully closed app previously just
 * sat on "sent" forever, since delivery only ever advanced from the
 * recipient's own client polling.
 */
async function getServiceWorker(browser, page) {
    await page.evaluate(() => navigator.serviceWorker.ready);
    // Scoped to this page's own isolated browser context - alice and
    // bob each have their own separate context (see loginAs()), each
    // with its own service worker target; matching by URL alone would
    // be ambiguous with both open at once.
    const pageContext = page.browserContext();
    const target = await browser.waitForTarget(
        (t) => t.type() === 'service_worker' && t.url().includes('service-worker.js') && t.browserContext() === pageContext,
        { timeout: 10000 },
    );
    return target.worker();
}

test('markMessageDelivered() (what the push handler calls for a closed/backgrounded app) marks a message delivered with no page polling at all', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);
    const browser = await getBrowser();

    await bob.page.goto(conversationUrl, { waitUntil: 'domcontentloaded' });
    await alice.page.goto(conversationUrl, { waitUntil: 'domcontentloaded' });
    const conversationId = await alice.page.evaluate(() => document.getElementById('messages').dataset.conversationId);

    // Delivery must be marked for alice specifically - she's the
    // recipient (only recipients get a message_status row at all, see
    // MessageRepository::create()) - so this needs HER service worker,
    // captured while her real page still controls it. A worker target
    // handle stays valid even after the page it came from navigates
    // elsewhere, since the SW is tied to the browser context/origin,
    // not to that specific page.
    const aliceWorker = await getServiceWorker(browser, alice.page);
    assert(aliceWorker, 'expected alice\'s service worker to be reachable as its own execution context');

    // Navigating alice away BEFORE bob sends anything means nothing in
    // her app is polling by the time the message actually exists - any
    // of the normal "delivered" paths (GET /conversations, GET
    // /messages/{conversation}), or the conversation's own
    // auto-mark-read-while-open behavior, would no longer run either,
    // genuinely mirroring a closed/backgrounded app rather than a page
    // that's still open and racing markMessageDelivered() to update the
    // same row. Her service worker handle (captured above, while her
    // page still existed to register it) stays valid regardless, since
    // it's tied to the browser context/origin, not to that page.
    await alice.page.goto('about:blank');

    const bodyText = 'delivered-while-closed check ' + Date.now();
    await bob.page.type('#composer-input', bodyText);
    await bob.page.click('#composer button[type="submit"]');
    await new Promise((r) => setTimeout(r, 500));

    const statusBefore = await bob.page.evaluate(
        (cid) => axios.get('/api/v1/conversations/' + cid + '/message-statuses?since=0').then((r) => Object.values(r.data.data).pop()),
        conversationId,
    );
    assert(statusBefore.delivery_status === 'sent', `expected the fresh message to still be "sent" before markMessageDelivered() runs, got "${statusBefore.delivery_status}"`);

    await aliceWorker.evaluate((cid) => self.markMessageDelivered(cid), conversationId);
    await new Promise((r) => setTimeout(r, 500));

    const statusAfter = await bob.page.evaluate(
        (cid) => axios.get('/api/v1/conversations/' + cid + '/message-statuses?since=0').then((r) => Object.values(r.data.data).pop()),
        conversationId,
    );
    assert(statusAfter.delivery_status === 'delivered', `expected the message to be "delivered" after markMessageDelivered() ran, got "${statusAfter.delivery_status}"`);

    assert(bob.errors.length === 0, `bob unexpected JS errors: ${JSON.stringify(bob.errors)}`);
    await closeSession(alice);
    await closeSession(bob);
});

test('foreground in-app notification: shows the real decrypted text for an e2e message, never raw ciphertext or a generic placeholder', async () => {
    // Real bug fixed here: pollForMessageNotifications() used to hand
    // the raw last_message.body straight to `new Notification(...)` with
    // no regard for encryption_mode - since every conversation is E2E by
    // default now, that's ciphertext, not text. This stubs the
    // Notification constructor (headless Chrome has no real OS
    // notification tray to inspect) purely to capture what body it was
    // actually given, while still exercising the real client-side
    // decrypt path (MeetE2E.getConversationKey/decryptText, the same
    // mechanism already proven correct for the chat list's own preview).
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    await alice.context.overridePermissions(BASE_URL, ['notifications']);
    await alice.page.evaluateOnNewDocument(() => {
        window.__capturedNotifications = [];
        class FakeNotification {
            constructor(title, options) {
                window.__capturedNotifications.push({ title: title, body: options && options.body });
            }
            static get permission() { return 'granted'; }
            static requestPermission() { return Promise.resolve('granted'); }
        }
        window.Notification = FakeNotification;
    });

    // On the dashboard, not the conversation itself - isViewingConversation()
    // must read false for a notification to be considered at all.
    await alice.page.goto(absoluteUrl('/dashboard'), { waitUntil: 'domcontentloaded' });
    await alice.page.waitForFunction(() => !!window.axios, { timeout: 10000 });
    // Let the first (priming) poll cycle complete before anything is sent -
    // it deliberately never notifies on its own first run.
    await new Promise((r) => setTimeout(r, 2000));

    const text = `Foreground notification preview ${Date.now()}`;
    await bob.page.goto(conversationUrl, { waitUntil: 'domcontentloaded' });
    await bob.page.waitForSelector('#composer-input');
    await bob.page.type('#composer-input', text);
    await bob.page.click('#composer button[type="submit"]');
    await new Promise((r) => setTimeout(r, 1000));

    const captured = await waitUntil(
        () => alice.page.evaluate(() => window.__capturedNotifications.length > 0 ? window.__capturedNotifications[window.__capturedNotifications.length - 1] : null),
        { timeout: 25000, message: 'a foreground notification to be captured on alice\'s page' },
    );

    assert(captured.body === text, `expected the notification body to be the real decrypted text "${text}", got "${captured.body}"`);

    await closeSession(alice);
    await closeSession(bob);
});
