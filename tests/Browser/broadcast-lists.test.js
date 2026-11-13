import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, absoluteUrl } from './support/session.js';
import { ensureContacts, FULL_NAMES } from './support/fixtures.js';

test('creating a broadcast list and sending a message delivers it as separate 1:1 chats to each recipient', async () => {
    await ensureContacts(USERS.alice, USERS.bob);
    await ensureContacts(USERS.alice, USERS.carol);

    const alice = await loginAs(USERS.alice);
    const { page } = alice;

    await page.goto(absoluteUrl('/broadcast-lists/create'), { waitUntil: 'networkidle0' });
    await page.type('#name', 'Test Broadcast ' + Date.now());

    const bobName = FULL_NAMES[USERS.bob];
    const carolName = FULL_NAMES[USERS.carol];
    await page.evaluate((bobName, carolName) => {
        [...document.querySelectorAll('label')].forEach((label) => {
            if (label.textContent.includes(bobName) || label.textContent.includes(carolName)) {
                label.querySelector('input[type="checkbox"]').checked = true;
            }
        });
    }, bobName, carolName);

    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => document.querySelector('form[action$="/broadcast-lists"]').submit()),
    ]);

    assert(page.url().includes('/broadcast-lists/'), `expected to land on the new broadcast list's page, got ${page.url()}`);

    const uniqueBody = 'Broadcast test message ' + Date.now();
    await page.type('textarea[name="body"]', uniqueBody);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => document.querySelector('form[action$="/send"]').submit()),
    ]);

    const status = await page.evaluate(() => document.body.textContent);
    assert(status.includes('Message sent to 2 recipient'), 'expected a "Message sent to 2 recipient(s)" status message');

    await closeSession(alice);

    // Both Bob and Carol should see it as a normal 1:1 message from Alice.
    const bob = await loginAs(USERS.bob);
    await bob.page.goto(absoluteUrl('/chat'), { waitUntil: 'networkidle0' });
    await bob.page.evaluate((name) => {
        const link = [...document.querySelectorAll('a')].find((a) => a.textContent.includes(name));
        link?.click();
    }, FULL_NAMES[USERS.alice]);
    await bob.page.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {});
    await new Promise((r) => setTimeout(r, 500));
    const bobText = await bob.page.$eval('#messages', (el) => el.textContent);
    assert(bobText.includes(uniqueBody), 'expected Bob to see the broadcast message in his own 1:1 chat with Alice');

    const carol = await loginAs(USERS.carol);
    await carol.page.goto(absoluteUrl('/chat'), { waitUntil: 'networkidle0' });
    await carol.page.evaluate((name) => {
        const link = [...document.querySelectorAll('a')].find((a) => a.textContent.includes(name));
        link?.click();
    }, FULL_NAMES[USERS.alice]);
    await carol.page.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {});
    await new Promise((r) => setTimeout(r, 500));
    const carolText = await carol.page.$eval('#messages', (el) => el.textContent);
    assert(carolText.includes(uniqueBody), 'expected Carol to see the same broadcast message in her own, separate 1:1 chat with Alice');

    assert(bob.errors.length === 0, `bob unexpected JS errors: ${JSON.stringify(bob.errors)}`);
    assert(carol.errors.length === 0, `carol unexpected JS errors: ${JSON.stringify(carol.errors)}`);

    await closeSession(bob);
    await closeSession(carol);
});
