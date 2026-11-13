import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, absoluteUrl } from './support/session.js';

function isHidden(page, selector) {
    return page.evaluate((sel) => document.getElementById(sel).classList.contains('hidden'), selector);
}

test('the chat list "+" button offers both New chat and New group, not just a bare link to Contacts', async () => {
    const alice = await loginAs(USERS.alice);
    const { page } = alice;

    await page.goto(absoluteUrl('/chat'), { waitUntil: 'networkidle0' });

    assert(await isHidden(page, 'chat-new-menu'), 'the new-chat/new-group menu should start closed');

    await page.click('#chat-new-btn');
    assert(!(await isHidden(page, 'chat-new-menu')), 'clicking the + button should open the menu');

    const [newChatHref, newGroupHref] = await page.$$eval('#chat-new-menu a', (links) => links.map((a) => a.getAttribute('href')));
    assert(newChatHref.includes('/contacts'), `expected "New chat" to link to Contacts, got "${newChatHref}"`);
    assert(newGroupHref.includes('/groups/create'), `expected "New group" to link to groups.create, got "${newGroupHref}"`);

    // Clicking elsewhere on the page closes it again.
    await page.click('#chat-list-header h1');
    assert(await isHidden(page, 'chat-new-menu'), 'clicking outside the menu should close it');

    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('#chat-new-btn').then(() => page.click('#chat-new-menu a[href*="/groups/create"]')),
    ]);
    assert(page.url().includes('/groups/create'), `expected navigation to groups/create, got "${page.url()}"`);

    assert(alice.errors.length === 0, `unexpected JS errors: ${JSON.stringify(alice.errors)}`);

    await closeSession(alice);
});
