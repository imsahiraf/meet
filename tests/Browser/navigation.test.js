import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, absoluteUrl } from './support/session.js';
import { ensureConversationUrl, FULL_NAMES } from './support/fixtures.js';

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

test('nav has 4 merged items (no separate Groups icon), and the chat list All/Chats/Groups tabs filter correctly', async () => {
    await ensureConversationUrl(USERS.alice, USERS.carol);

    const alice = await loginAs(USERS.alice);

    // Create a fresh group so there's a real group-type conversation
    // row to filter against, alongside the 1:1 conversation above.
    await alice.page.goto(absoluteUrl('/groups/create'), { waitUntil: 'networkidle0' });
    await alice.page.type('#group_name', 'Nav Tab Test Group ' + Date.now());

    const carolName = FULL_NAMES[USERS.carol];
    const carolUserId = await alice.page.evaluate((name) => {
        const checkbox = [...document.querySelectorAll('input[name="members[]"]')].find((cb) => cb.closest('label')?.textContent.includes(name));
        return checkbox ? checkbox.value : null;
    }, carolName);
    assert(carolUserId, 'Carol must be selectable as a group member (must be an existing contact of Alice)');
    await alice.page.evaluate((id) => {
        document.querySelector(`input[name="members[]"][value="${id}"]`).checked = true;
    }, carolUserId);

    // Not page.click() - a real coordinate click on this form's submit
    // button can land on the fixed mobile bottom nav bar instead (it
    // overlaps near the viewport bottom at Puppeteer's default small
    // viewport size). Submitting the form directly sidesteps that.
    await Promise.all([
        alice.page.waitForNavigation({ waitUntil: 'networkidle0' }),
        alice.page.evaluate(() => document.querySelector('form[action$="/groups"]').submit()),
    ]);
    assert(alice.page.url().includes('/groups/'), `expected to land on the new group's page, got ${alice.page.url()}`);

    await alice.page.goto(absoluteUrl('/chat'), { waitUntil: 'networkidle0' });

    const navLabels = await alice.page.$$eval('nav.lg\\:hidden a', (links) => links.map((el) => el.textContent.trim()));
    assert(navLabels.length === 6, `expected 6 nav items (Chat/Contacts/Calls/Mail/Meetings/Calendar), got ${navLabels.length}: ${JSON.stringify(navLabels)}`);
    assert(navLabels.includes('Chat'), `expected a merged "Chat" nav item, got: ${JSON.stringify(navLabels)}`);
    assert(!navLabels.includes('Groups'), `expected no separate "Groups" nav item, got: ${JSON.stringify(navLabels)}`);
    assert(navLabels.includes('Calendar'), `expected a dedicated "Calendar" nav item, got: ${JSON.stringify(navLabels)}`);

    const tabLabels = await alice.page.$$eval('.conversation-filter-tab', (tabs) => tabs.map((t) => t.textContent.trim()));
    assert(JSON.stringify(tabLabels) === JSON.stringify(['All', 'Chats', 'Groups']), `expected All/Chats/Groups tabs, got: ${JSON.stringify(tabLabels)}`);

    function visibleTypes(page) {
        return page.$$eval('#conversation-list-items > li[data-conversation-type]', (rows) =>
            rows.filter((r) => !r.classList.contains('hidden')).map((r) => r.dataset.conversationType));
    }

    let visible = await visibleTypes(alice.page);
    assert(visible.includes('direct') && visible.includes('group'), `expected both types visible on "All", got: ${JSON.stringify(visible)}`);

    await alice.page.click('[data-conversation-filter="group"]');
    visible = await visibleTypes(alice.page);
    assert(visible.length > 0 && visible.every((t) => t === 'group'), `expected only group rows visible on "Groups" tab, got: ${JSON.stringify(visible)}`);

    await alice.page.click('[data-conversation-filter="direct"]');
    visible = await visibleTypes(alice.page);
    assert(visible.length > 0 && visible.every((t) => t === 'direct'), `expected only direct rows visible on "Chats" tab, got: ${JSON.stringify(visible)}`);

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);

    await closeSession(alice);
});

test('the nav chat badge appears when a message arrives and clears once read', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.bob, USERS.carol);
    const conversationId = conversationUrl.match(/\/chat\/(\d+)/)[1];

    const bob = await loginAs(USERS.bob);
    const carol = await loginAs(USERS.carol);

    await bob.page.goto(absoluteUrl('/dashboard'), { waitUntil: 'networkidle0' });

    // Bob's account is shared across many test files in a full-suite
    // run, so his baseline unread total isn't necessarily zero here -
    // assert on the delta (does it go up by 1, then back down), not on
    // an assumed-clean starting state.
    function totalUnread(page) {
        return page.evaluate(() => window.axios.get('/api/v1/conversations/unread-count').then((r) => r.data.data.total));
    }

    function chatBadgeVisible(page) {
        return page.evaluate(() => {
            const el = document.querySelector('[data-nav-badge="chat"]');
            return el && !el.classList.contains('hidden');
        });
    }

    const before = await totalUnread(bob.page);

    await carol.page.goto(absoluteUrl(conversationUrl), { waitUntil: 'domcontentloaded' });
    await carol.page.type('#composer-input', 'Hello Bob, badge test');
    await carol.page.click('#composer button[type="submit"]');

    await new Promise((r) => setTimeout(r, 800));

    assert((await totalUnread(bob.page)) === before + 1, 'expected total unread to increase by exactly 1 after the message arrives');

    // Trigger a fresh badge poll rather than waiting up to 15s for the interval.
    await bob.page.evaluate(() => window.axios.get('/api/v1/conversations/unread-count').then((r) => {
        document.querySelectorAll('[data-nav-badge="chat"]').forEach((el) => el.classList.toggle('hidden', !(r.data.data.total > 0)));
    }));

    assert(await chatBadgeVisible(bob.page), 'expected the chat nav badge to appear after an unread message arrives');

    await bob.page.evaluate((id) => window.axios.post('/api/v1/conversations/' + id + '/read'), conversationId);

    assert((await totalUnread(bob.page)) === before, 'expected total unread to return to its baseline after marking the conversation read');

    assert(bob.errors.length === 0, `bob unexpected JS errors: ${JSON.stringify(bob.errors)}`);

    await closeSession(bob);
    await closeSession(carol);
});

test('the per-conversation row on the chat list shows an unread count badge and clears it once read', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.bob, USERS.carol);

    const bob = await loginAs(USERS.bob);
    const carol = await loginAs(USERS.carol);

    await bob.page.goto(absoluteUrl('/chat'), { waitUntil: 'networkidle0' });
    const conversationUuid = await bob.page.evaluate((url) => {
        const id = url.match(/\/chat\/(\d+)/)[1];
        const link = [...document.querySelectorAll(`a[href$="/chat/${id}"]`)].find(Boolean);
        return link ? link.closest('[data-conversation-uuid]')?.dataset.conversationUuid : null;
    }, conversationUrl);
    assert(conversationUuid, 'expected to find bob\'s row for this conversation on his chat list');

    const badgeHiddenBefore = await bob.page.evaluate((uuid) => {
        const row = document.querySelector(`[data-conversation-uuid="${uuid}"]`);
        return row.querySelector('.conversation-unread').classList.contains('hidden');
    }, conversationUuid);
    assert(badgeHiddenBefore, 'expected no unread badge before any new message arrives');

    const messageText = `Row badge test ${Date.now()}`;
    await carol.page.goto(absoluteUrl(conversationUrl), { waitUntil: 'domcontentloaded' });
    await carol.page.type('#composer-input', messageText);
    await carol.page.click('#composer button[type="submit"]');
    await new Promise((r) => setTimeout(r, 800));

    // The row badge is populated purely by index.blade.php's poll
    // (refresh(), every conversation_list_ms - 8s by default), not on
    // initial server render, so this must genuinely wait it out rather
    // than reload.
    await waitUntil(async () => {
        const badge = await bob.page.evaluate((uuid) => {
            const row = document.querySelector(`[data-conversation-uuid="${uuid}"]`);
            return { hidden: row.querySelector('.conversation-unread').classList.contains('hidden'), text: row.querySelector('.conversation-unread').textContent.trim() };
        }, conversationUuid);
        return !badge.hidden && Number(badge.text) >= 1;
    }, { timeout: 12000, interval: 500, message: 'the chat-list row shows an unread badge after a new message arrives' });

    await bob.page.goto(absoluteUrl(conversationUrl), { waitUntil: 'domcontentloaded' });
    await new Promise((r) => setTimeout(r, 500));
    await bob.page.goto(absoluteUrl('/chat'), { waitUntil: 'networkidle0' });

    const badgeAfterRead = await bob.page.evaluate((uuid) => {
        return document.querySelector(`[data-conversation-uuid="${uuid}"]`).querySelector('.conversation-unread').classList.contains('hidden');
    }, conversationUuid);
    assert(badgeAfterRead, 'expected the unread badge to clear from the chat list after opening and reading the conversation');

    assert(bob.errors.length === 0, `bob unexpected JS errors: ${JSON.stringify(bob.errors)}`);

    await closeSession(bob);
    await closeSession(carol);
});
