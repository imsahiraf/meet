import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, absoluteUrl } from './support/session.js';
import { ensureConversationUrl } from './support/fixtures.js';

async function conversationIdFromUrl(url) {
    return url.match(/\/chat\/(\d+)/)[1];
}

async function selectConversations(page, ids) {
    await page.click('#chat-select-btn');
    for (const id of ids) {
        await page.click(`.conversation-select-checkbox[data-conversation-id="${id}"]`);
    }
}

test('multi-select: bulk archive, unarchive, mute, and delete apply to every checked conversation', async () => {
    const bobUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const carolUrl = await ensureConversationUrl(USERS.alice, USERS.carol);
    const bobId = await conversationIdFromUrl(bobUrl);
    const carolId = await conversationIdFromUrl(carolUrl);

    const alice = await loginAs(USERS.alice);
    const { page } = alice;

    // Bulk archive both.
    await page.goto(absoluteUrl('/chat'), { waitUntil: 'networkidle0' });
    await selectConversations(page, [bobId, carolId]);
    const countAfterSelect = await page.$eval('#chat-bulk-count', (el) => el.textContent);
    assert(countAfterSelect === '2', `expected the bulk bar to show 2 selected, got ${countAfterSelect}`);

    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('#chat-bulk-archive-btn'),
    ]);

    await page.goto(absoluteUrl('/chat/archived'), { waitUntil: 'networkidle0' });
    const archivedIds = await page.$$eval('[data-conversation-id]', (els) => els.map((el) => el.dataset.conversationId));
    assert(archivedIds.includes(bobId) && archivedIds.includes(carolId), `expected both conversations in the archived list, got ${JSON.stringify(archivedIds)}`);

    // Bulk unarchive both back.
    await selectConversations(page, [bobId, carolId]);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('#chat-bulk-archive-btn'),
    ]);

    await page.goto(absoluteUrl('/chat/archived'), { waitUntil: 'networkidle0' });
    const archivedIdsAfter = await page.$$eval('[data-conversation-id]', (els) => els.map((el) => el.dataset.conversationId));
    assert(!archivedIdsAfter.includes(bobId) && !archivedIdsAfter.includes(carolId), 'expected both conversations to have left the archived list after bulk unarchive');

    // Bulk mute just the bob conversation.
    await page.goto(absoluteUrl('/chat'), { waitUntil: 'networkidle0' });
    await selectConversations(page, [bobId]);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('#chat-bulk-mute-btn'),
    ]);

    const showsMutedIcon = await page.evaluate((id) => {
        const row = document.querySelector(`[data-conversation-id="${id}"]`);
        return row ? row.querySelector('[title="Muted"]') !== null : false;
    }, bobId);
    assert(showsMutedIcon, 'expected the bulk-muted conversation to show the muted indicator after reload');

    // Bulk delete the carol conversation. The delete confirmation is
    // now the app's own themed MeetDialog (an in-page modal), not a
    // native confirm() - Puppeteer's 'dialog' event never fires for
    // it, so the fix is to click its own OK button once it appears.
    await selectConversations(page, [carolId]);
    await page.click('#chat-bulk-delete-btn');
    await page.waitForSelector('#meet-dialog-overlay.flex');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('#meet-dialog-ok'),
    ]);

    const idsAfterDelete = await page.$$eval('[data-conversation-id]', (els) => els.map((el) => el.dataset.conversationId));
    assert(!idsAfterDelete.includes(carolId), 'expected the bulk-deleted conversation to be gone from the chat list');

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);

    await closeSession(alice);
});

test('multi-select: Cancel exits select mode without applying any action, and re-entering starts with a clean selection', async () => {
    const bobUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const bobId = await conversationIdFromUrl(bobUrl);

    const alice = await loginAs(USERS.alice);
    const { page } = alice;

    await page.goto(absoluteUrl('/chat'), { waitUntil: 'networkidle0' });
    await selectConversations(page, [bobId]);
    await page.click('#chat-bulk-cancel-btn');

    const headerHidden = await page.evaluate(() => document.getElementById('chat-list-header').classList.contains('hidden'));
    const bulkBarHidden = await page.evaluate(() => document.getElementById('chat-bulk-bar').classList.contains('hidden'));
    assert(!headerHidden, 'expected the normal header to reappear after Cancel');
    assert(bulkBarHidden, 'expected the bulk action bar to hide after Cancel');

    // Re-entering select mode must start with everything unchecked.
    await page.click('#chat-select-btn');
    const anyChecked = await page.$$eval('.conversation-select-checkbox', (els) => els.some((el) => el.checked));
    assert(!anyChecked, 'expected a fresh select-mode session to start with no checkboxes pre-checked');

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);

    await closeSession(alice);
});
