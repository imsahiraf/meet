import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, absoluteUrl } from './support/session.js';
import { ensureConversationUrl } from './support/fixtures.js';

async function waitUntil(fn, { timeout = 10000, interval = 250, message = 'condition' } = {}) {
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

test('chat pagination: scrolling to the top loads the FULL history, one page at a time, with no duplicates or gaps', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);

    await alice.page.goto(conversationUrl, { waitUntil: 'domcontentloaded' });
    await alice.page.waitForFunction(() => !!window.axios, { timeout: 10000 });

    const conversationId = await alice.page.evaluate(() => parseInt(document.getElementById('messages').dataset.conversationId, 10));
    assert(conversationId, `could not read a conversation id off #messages at ${conversationUrl}`);

    // 45 messages - comfortably more than one server page (30), so
    // reaching the very first one genuinely requires the client to page
    // backward more than once. Sent directly via the API (not typed
    // through the composer) purely for test speed - what's under test is
    // the read/scroll path, not sending.
    const total = 45;
    await alice.page.evaluate(async (conversationId, total) => {
        for (let i = 1; i <= total; i++) {
            await axios.post('/api/v1/messages', { conversation_id: conversationId, body: `History message ${i}` });
        }
    }, conversationId, total);

    await alice.page.goto(conversationUrl, { waitUntil: 'domcontentloaded' });
    await alice.page.waitForFunction(() => document.querySelectorAll('#messages [data-message-id]').length > 0, { timeout: 10000 });

    function loadedBodies(page) {
        return page.evaluate(() => [...document.querySelectorAll('#messages [data-message-id]')].map((el) => el.dataset.body));
    }

    // Only the most recent page is loaded up front - the very first
    // message sent must NOT be there yet.
    const initialBodies = await loadedBodies(alice.page);
    assert(!initialBodies.includes('History message 1'), 'expected the oldest message to be absent before scrolling up (only the latest page should load initially)');
    assert(initialBodies.includes(`History message ${total}`), 'expected the most recent message to be visible on initial load');

    // Scroll to the very top repeatedly - each trip near the top should
    // trigger loading one more page of older history, until the real
    // beginning of the conversation is reached.
    await waitUntil(async () => {
        await alice.page.evaluate(() => { document.getElementById('messages').scrollTop = 0; });
        await new Promise((r) => setTimeout(r, 400));
        const bodies = await loadedBodies(alice.page);
        return bodies.includes('History message 1');
    }, { timeout: 15000, interval: 500, message: 'the oldest message (History message 1) to appear after scrolling to the top' });

    const finalBodies = await loadedBodies(alice.page);

    // Every one of the 45 messages made it in, and nothing arrived twice.
    for (let i = 1; i <= total; i++) {
        assert(finalBodies.includes(`History message ${i}`), `expected "History message ${i}" to be present after paging to the beginning`);
    }
    assert(finalBodies.length === total, `expected exactly ${total} rendered messages with no duplicates, got ${finalBodies.length}`);

    // Scrolling to the top again once the beginning is reached must not
    // error out or duplicate anything further.
    await alice.page.evaluate(() => { document.getElementById('messages').scrollTop = 0; });
    await new Promise((r) => setTimeout(r, 800));
    const afterExtraScroll = await loadedBodies(alice.page);
    assert(afterExtraScroll.length === total, `expected still exactly ${total} messages after an extra scroll past the beginning, got ${afterExtraScroll.length}`);

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);

    await closeSession(alice);
});
