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
    return page.evaluate((sel) => document.getElementById(sel).classList.contains('hidden'), selector);
}

test('guest whiteboard: a guest with no account can open the whiteboard and draw, and the registered host sees the stroke', async () => {
    const alice = await loginAs(USERS.alice);
    const guest = await openGuestSession();

    const instantResponse = await alice.page.evaluate(async () => {
        const res = await fetch('/api/v1/meetings/instant', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify({ title: 'Guest whiteboard test meeting' }),
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
    await guest.page.type('#guest-name-input', 'Whiteboard Wendy');
    await guest.page.click('#guest-join-btn');
    await waitUntil(() => guest.page.url().includes('/meetings/guest/'), { message: 'guest is redirected to the guest room page' });
    await waitUntil(async () => !(await isHidden(guest.page, 'room-panel')), { timeout: 10000, message: 'guest enters the room' });

    // Guest opens the whiteboard - button and overlay exist at all,
    // which they never did before this fix (guest-room.blade.php had
    // zero whiteboard markup).
    await guest.page.click('#whiteboard-btn');
    await waitUntil(async () => !(await isHidden(guest.page, 'whiteboard-overlay')), { message: 'the whiteboard overlay opens for the guest' });

    // Draw a real pen stroke via synthetic mouse events on the canvas.
    const canvasBox = await guest.page.$eval('#wb-canvas', (el) => {
        const rect = el.getBoundingClientRect();
        return { x: rect.x, y: rect.y };
    });
    await guest.page.mouse.move(canvasBox.x + 20, canvasBox.y + 20);
    await guest.page.mouse.down();
    await guest.page.mouse.move(canvasBox.x + 80, canvasBox.y + 80, { steps: 5 });
    await guest.page.mouse.up();
    await new Promise((r) => setTimeout(r, 800));

    // The host opens the same whiteboard and, via polling, should see
    // the guest's stroke rendered - proving the guest's object actually
    // reached the shared whiteboard, not just drawn locally.
    await alice.page.click('#whiteboard-btn');
    await waitUntil(async () => !(await isHidden(alice.page, 'whiteboard-overlay')), { message: 'the whiteboard overlay opens for alice' });
    await new Promise((r) => setTimeout(r, 1500));

    // The canvas isn't blank - something was actually drawn/rendered
    // from the poll response, not just left empty.
    const hasDrawnPixels = await alice.page.evaluate(() => {
        const canvas = document.getElementById('wb-canvas');
        const ctx = canvas.getContext('2d');
        const data = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
        for (let i = 3; i < data.length; i += 4) {
            if (data[i] !== 0) return true;
        }
        return false;
    });
    assert(hasDrawnPixels, "expected alice's whiteboard canvas to show the guest's stroke after polling");

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);
    assert(guest.errors.length === 0, `guest unexpected JS errors: ${JSON.stringify(guest.errors)}`);

    await closeSession(alice);
    await guest.context.close();
});
