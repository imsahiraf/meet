import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, absoluteUrl } from './support/session.js';

async function waitUntil(fn, { timeout = 10000, interval = 200, message = 'condition' } = {}) {
    const start = Date.now();
    while (Date.now() - start < timeout) {
        if (await fn()) return true;
        await new Promise((r) => setTimeout(r, interval));
    }
    throw new Error(`Timed out waiting for: ${message}`);
}

test('calendar month view fits within the viewport on mobile without needing the page to scroll', async () => {
    const alice = await loginAs(USERS.alice);
    const { page } = alice;

    await page.setViewport({ width: 375, height: 667 });
    await page.goto(absoluteUrl('/meetings/calendar'), { waitUntil: 'networkidle0' });

    await waitUntil(async () => {
        const height = await page.$eval('#cal-grid', (el) => parseFloat(el.style.height) || 0);
        return height > 0;
    }, { message: 'the month grid to be given a fitted height' });

    // <main> is the app's own internally-scrolling container - its
    // scrollHeight must not exceed its clientHeight, i.e. no scrolling
    // needed to see the rest of the month.
    const overflow = await page.evaluate(() => {
        const main = document.getElementById('app-main');
        return main.scrollHeight - main.clientHeight;
    });
    assert(overflow <= 1, `expected the month view to fit without scrolling, but overflowed by ${overflow}px`);

    // Same check holds for a 6-row month (some months lay out as 6
    // week-rows instead of 5) - flip forward until we land on one.
    let foundSixRowMonth = false;
    for (let i = 0; i < 14 && !foundSixRowMonth; i++) {
        const rows = await page.$eval('#cal-grid', (el) => el.children.length / 7);
        if (rows === 6) {
            foundSixRowMonth = true;
            break;
        }
        await page.click('#cal-next');
        await new Promise((r) => setTimeout(r, 300));
    }
    assert(foundSixRowMonth, 'expected to find at least one 6-row month within the next 14 months to verify against');

    const overflowSixRow = await page.evaluate(() => {
        const main = document.getElementById('app-main');
        return main.scrollHeight - main.clientHeight;
    });
    assert(overflowSixRow <= 1, `expected a 6-row month to also fit without scrolling, but overflowed by ${overflowSixRow}px`);

    assert(alice.errors.length === 0, `unexpected JS errors: ${JSON.stringify(alice.errors)}`);

    await closeSession(alice);
});
