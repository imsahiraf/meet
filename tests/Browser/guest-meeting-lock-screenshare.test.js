import { test, assert } from './support/test.js';
import { closeSession, getBrowser, absoluteUrl } from './support/session.js';

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

test('the guest host of a self-created meeting sees a working Lock button (a non-host guest never does), and both can share their screen', async () => {
    const host = await openGuestSession();
    const guest = await openGuestSession();

    await host.page.goto(absoluteUrl('/meetings/guest/new'), { waitUntil: 'networkidle0' });
    await host.page.type('input[name="guest_name"]', 'Host Holly');
    await host.page.type('input[name="title"]', 'Lock and share test');
    await host.page.click('button[type="submit"]');
    await waitUntil(() => host.page.url().includes('/meetings/guest/'), { message: 'host is redirected to the guest room page' });
    await waitUntil(async () => !(await isHidden(host.page, 'room-panel')), { message: 'host enters the room' });

    // The lock button previously didn't exist in this room's markup at
    // all - the guest-creator (this room's real host) must see it.
    await waitUntil(async () => !(await isHidden(host.page, 'lock-btn')), { timeout: 8000, message: 'the host sees the Lock button' });

    const shareLink = await host.page.$eval('#share-link-value', (el) => el.textContent.trim());
    await guest.page.goto(shareLink, { waitUntil: 'networkidle0' });
    await guest.page.type('#guest-name-input', 'Plain Pat');
    await guest.page.click('#guest-join-btn');
    await waitUntil(() => guest.page.url().includes('/meetings/guest/'), { message: 'guest is redirected to the guest room page' });
    await waitUntil(async () => !(await isHidden(guest.page, 'room-panel')), { timeout: 10000, message: 'guest enters the room' });

    // A non-host guest must never see the Lock button.
    await new Promise((r) => setTimeout(r, 1000));
    assert(await isHidden(guest.page, 'lock-btn'), 'expected a non-host guest to never see the Lock button');

    // Lock it - then confirm server-side (not just a local label flip)
    // via the same status endpoint the room itself polls.
    await host.page.click('#lock-btn');
    await waitUntil(async () => {
        const text = await host.page.$eval('#lock-btn span', (el) => el.textContent);
        return text === 'Unlock';
    }, { message: 'the lock button flips to "Unlock" after locking' });

    const guestToken = guest.page.url().split('/meetings/guest/')[1];
    await waitUntil(async () => {
        const status = await guest.page.evaluate((token) => axios.get('/api/v1/meetings/guest/' + token + '/status').then((r) => r.data.data.meeting?.status), guestToken);
        return status === 'locked';
    }, { timeout: 8000, message: 'the meeting\'s real status becomes "locked" (not just a local UI toggle)' });

    // Unlock again.
    await host.page.click('#lock-btn');
    await waitUntil(async () => {
        const text = await host.page.$eval('#lock-btn span', (el) => el.textContent);
        return text === 'Lock';
    }, { message: 'the lock button flips back to "Lock" after unlocking' });
    await waitUntil(async () => {
        const status = await guest.page.evaluate((token) => axios.get('/api/v1/meetings/guest/' + token + '/status').then((r) => r.data.data.meeting?.status), guestToken);
        return status === 'active';
    }, { timeout: 8000, message: 'the meeting\'s real status returns to "active" after unlocking' });

    // ---- Screen share ----
    await host.page.click('#screen-share-btn');
    await waitUntil(async () => {
        const text = await host.page.$eval('#screen-share-btn span', (el) => el.textContent);
        return text === 'Stop sharing';
    }, { timeout: 8000, message: 'the host\'s Share screen button flips to "Stop sharing"' });

    await waitUntil(async () => {
        return guest.page.$$eval('#video-grid video', (els) => els.some((v) => v.srcObject && v.srcObject.getVideoTracks().length > 0));
    }, { timeout: 10000, message: 'the guest actually receives a real video track from the host\'s screen share' });

    await host.page.click('#screen-share-btn');
    await waitUntil(async () => {
        const text = await host.page.$eval('#screen-share-btn span', (el) => el.textContent);
        return text === 'Share screen';
    }, { message: 'the host\'s Share screen button flips back after stopping' });

    assert(host.errors.length === 0, `host unexpected JS errors: ${JSON.stringify(host.errors)}`);
    assert(guest.errors.length === 0, `guest unexpected JS errors: ${JSON.stringify(guest.errors)}`);

    await closeSession(host);
    await closeSession(guest);
});
