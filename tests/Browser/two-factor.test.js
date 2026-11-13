import crypto from 'node:crypto';
import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, absoluteUrl, PASSWORD } from './support/session.js';

// Minimal RFC 6238 TOTP generator (no new npm dependency - just
// Node's built-in crypto) so this test can compute a real code from
// the secret shown on the Settings page, exactly like an authenticator
// app would, rather than needing a live device.
function base32Decode(base32) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of base32.replace(/=+$/, '').toUpperCase()) {
        const value = alphabet.indexOf(char);
        if (value === -1) continue;
        bits += value.toString(2).padStart(5, '0');
    }
    const bytes = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) {
        bytes.push(parseInt(bits.substring(i, i + 8), 2));
    }
    return Buffer.from(bytes);
}

function currentTotp(secret) {
    const key = base32Decode(secret);
    const counter = Math.floor(Date.now() / 1000 / 30);
    const counterBuffer = Buffer.alloc(8);
    counterBuffer.writeBigUInt64BE(BigInt(counter));

    const hmac = crypto.createHmac('sha1', key).update(counterBuffer).digest();
    const offset = hmac[hmac.length - 1] & 0xf;
    const binary = ((hmac[offset] & 0x7f) << 24) | (hmac[offset + 1] << 16) | (hmac[offset + 2] << 8) | hmac[offset + 3];

    return String(binary % 1_000_000).padStart(6, '0');
}

test('enabling two-factor authentication requires a real code, and logging back in requires that code too', async () => {
    const carol = await loginAs(USERS.carol);
    const { page } = carol;

    await page.goto(absoluteUrl('/profile/two-factor'), { waitUntil: 'networkidle0' });

    await page.evaluate(() => {
        const form = [...document.querySelectorAll('form')].find((f) => f.action.endsWith('/profile/two-factor') && f.method === 'post');
        form.submit();
    });
    await new Promise((r) => setTimeout(r, 500));

    const secret = await page.$eval('main', (el) => {
        const match = el.textContent.match(/manually:\s*([A-Z2-7]+)/);
        return match ? match[1] : null;
    });
    assert(secret, 'expected the manual-entry secret key to be shown on the page');

    const wrongCodeResult = await page.evaluate(() => {
        document.querySelector('input[name="code"]').value = '000000';
        return true;
    });
    assert(wrongCodeResult, 'expected to be able to fill the code field');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => document.querySelector('form[action$="/two-factor/confirm"]').submit()),
    ]);
    let bodyText = await page.evaluate(() => document.body.textContent);
    assert(bodyText.includes('incorrect') || bodyText.includes('expired'), 'expected an error for the wrong code');

    const realCode = currentTotp(secret);
    await page.evaluate((code) => { document.querySelector('input[name="code"]').value = code; }, realCode);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => document.querySelector('form[action$="/two-factor/confirm"]').submit()),
    ]);

    bodyText = await page.evaluate(() => document.body.textContent);
    assert(bodyText.includes('Two-factor authentication is now enabled'), 'expected a confirmation status message');
    assert(bodyText.includes('Save these recovery codes'), 'expected recovery codes to be shown once');
    assert(bodyText.includes('Two-factor authentication is enabled'), 'expected the enabled status indicator');

    await closeSession(carol);

    // Log back in from a fresh session - password alone must not be enough.
    const secondSession = await loginAs(USERS.carol);
    const { page: page2 } = secondSession;
    assert(page2.url().includes('two-factor-challenge'), `expected to land on the 2FA challenge page, got ${page2.url()}`);

    await page2.type('#code', currentTotp(secret));
    await Promise.all([
        page2.waitForNavigation({ waitUntil: 'networkidle0' }),
        page2.click('button[type="submit"]'),
    ]);
    assert(page2.url().includes('/chat'), `expected to land in chat after a correct 2FA code, got ${page2.url()}`);

    // Clean up: disable 2FA again so this fixture account doesn't stay
    // 2FA-locked for other browser test files reusing it.
    await page2.goto(absoluteUrl('/profile/two-factor'), { waitUntil: 'networkidle0' });
    await page2.evaluate((password) => {
        const form = [...document.querySelectorAll('form')].find((f) => f.action.endsWith('/two-factor') && f.querySelector('input[name="password"]'));
        form.querySelector('input[name="password"]').value = password;
    }, PASSWORD);
    await Promise.all([
        page2.waitForNavigation({ waitUntil: 'networkidle0' }),
        page2.evaluate(() => {
            const form = [...document.querySelectorAll('form')].find((f) => f.action.endsWith('/two-factor') && f.querySelector('input[name="password"]'));
            form.submit();
        }),
    ]);

    const finalBodyText = await page2.evaluate(() => document.body.textContent);
    assert(finalBodyText.includes('Two-factor authentication disabled'), 'expected 2FA to be disabled again during cleanup');

    assert(carol.errors.length === 0, `carol unexpected JS errors: ${JSON.stringify(carol.errors)}`);
    assert(secondSession.errors.length === 0, `second session unexpected JS errors: ${JSON.stringify(secondSession.errors)}`);

    await closeSession(secondSession);
});

test('checking "remember this device" on the 2FA challenge skips it on the next login from the same browser', async () => {
    const carol = await loginAs(USERS.carol);
    const { page } = carol;

    // Enable 2FA (same flow as the test above).
    await page.goto(absoluteUrl('/profile/two-factor'), { waitUntil: 'networkidle0' });
    await page.evaluate(() => {
        const form = [...document.querySelectorAll('form')].find((f) => f.action.endsWith('/profile/two-factor') && f.method === 'post');
        form.submit();
    });
    await new Promise((r) => setTimeout(r, 500));

    const secret = await page.$eval('main', (el) => {
        const match = el.textContent.match(/manually:\s*([A-Z2-7]+)/);
        return match ? match[1] : null;
    });
    assert(secret, 'expected the manual-entry secret key to be shown on the page');

    await page.evaluate((code) => { document.querySelector('input[name="code"]').value = code; }, currentTotp(secret));
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => document.querySelector('form[action$="/two-factor/confirm"]').submit()),
    ]);

    // Log out via the app's own logout button (not closeSession(),
    // which would destroy the whole cookie jar) - this and every
    // subsequent step stay on the same `page`/browser context, so a
    // real cookie set by "remember this device" genuinely persists
    // across these real navigations, the same way it would for an
    // actual user. Logout itself lives on the Account & Security page
    // now, not in the shared header/nav any more.
    await page.goto(absoluteUrl('/profile/security'), { waitUntil: 'networkidle0' });
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => document.querySelector('form[action$="/logout"]').submit()),
    ]);

    // A real logout invalidates the session out from under whichever
    // background poll (badge/heartbeat/notifications) happens to be in
    // flight at that exact moment; Chrome logs the resulting 401 to the
    // console itself regardless of whether the JS that made the request
    // handles it, so it's expected, harmless noise from this test's own
    // deliberate logout, not a real bug.
    carol.errors.length = 0;

    // First login after enabling: no trusted device yet, so it must challenge.
    await page.goto(absoluteUrl('/login'), { waitUntil: 'networkidle0' });
    await page.type('input[name="login"]', USERS.carol);
    await page.type('input[name="password"]', PASSWORD);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('button[type="submit"]'),
    ]);
    assert(page.url().includes('two-factor-challenge'), `expected the first login after enabling 2FA to challenge, got ${page.url()}`);

    // This time, check "remember this device" while completing the challenge.
    await page.type('#code', currentTotp(secret));
    await page.evaluate(() => { document.querySelector('input[name="remember_device"]').checked = true; });
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('button[type="submit"]'),
    ]);
    assert(page.url().includes('/chat'), `expected to land in chat after checking "remember this device", got ${page.url()}`);

    // Log out and log back in from the same browser - this time it
    // should skip the challenge entirely and land straight in chat.
    // Logout itself lives on the Account & Security page now, not in
    // the shared header/nav any more.
    await page.goto(absoluteUrl('/profile/security'), { waitUntil: 'networkidle0' });
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => document.querySelector('form[action$="/logout"]').submit()),
    ]);
    carol.errors.length = 0; // see the earlier logout above for why

    await page.goto(absoluteUrl('/login'), { waitUntil: 'networkidle0' });
    await page.type('input[name="login"]', USERS.carol);
    await page.type('input[name="password"]', PASSWORD);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('button[type="submit"]'),
    ]);
    assert(page.url().includes('/chat'), `expected the remembered device to skip the 2FA challenge entirely, got ${page.url()}`);
    assert(!page.url().includes('two-factor-challenge'), 'expected no two-factor-challenge redirect on a remembered device');

    // Clean up: disable 2FA again so this fixture account doesn't stay
    // 2FA-locked for other browser test files reusing it.
    await page.goto(absoluteUrl('/profile/two-factor'), { waitUntil: 'networkidle0' });
    await page.evaluate((password) => {
        const form = [...document.querySelectorAll('form')].find((f) => f.action.endsWith('/two-factor') && f.querySelector('input[name="password"]'));
        form.querySelector('input[name="password"]').value = password;
    }, PASSWORD);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => {
            const form = [...document.querySelectorAll('form')].find((f) => f.action.endsWith('/two-factor') && f.querySelector('input[name="password"]'));
            form.submit();
        }),
    ]);

    const finalBodyText = await page.evaluate(() => document.body.textContent);
    assert(finalBodyText.includes('Two-factor authentication disabled'), 'expected 2FA to be disabled again during cleanup');

    assert(carol.errors.length === 0, `carol unexpected JS errors: ${JSON.stringify(carol.errors)}`);

    await closeSession(carol);
});
