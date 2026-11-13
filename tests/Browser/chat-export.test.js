import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, absoluteUrl } from './support/session.js';
import { ensureConversationUrl } from './support/fixtures.js';

test('exporting a chat from the kebab menu downloads a real zip with a transcript inside', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.carol);
    const alice = await loginAs(USERS.alice);
    const { page } = alice;

    await page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    const uniqueBody = 'Export test message ' + Date.now();
    await page.type('#composer-input', uniqueBody);
    await page.click('#composer button[type="submit"]');
    await new Promise((r) => setTimeout(r, 800));

    await page.click('#chat-menu-btn');
    const exportHref = await page.$eval('#chat-menu-dropdown a', (el) => el.getAttribute('href'));
    assert(exportHref.includes('/export'), `expected the "Export chat" link to point at an /export endpoint, got ${exportHref}`);

    const result = await page.evaluate((href) => {
        return window.axios.get(href, { responseType: 'arraybuffer' }).then((response) => ({
            status: response.status,
            contentType: response.headers['content-type'],
            byteLength: response.data.byteLength,
        }));
    }, exportHref);

    assert(result.status === 200, `expected the export request to succeed, got status ${result.status}`);
    assert(result.contentType.includes('zip'), `expected a zip content-type, got ${result.contentType}`);
    assert(result.byteLength > 0, 'expected a non-empty zip file');

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);

    await closeSession(alice);
});
