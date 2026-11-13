import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, absoluteUrl } from './support/session.js';
import { ensureConversationUrl, ensureContacts } from './support/fixtures.js';

function noErrors(session, label) {
    assert(session.errors.length === 0, `${label} had JS errors: ${JSON.stringify(session.errors)}`);
}

async function sendMessage(page, text) {
    await page.type('#composer-input', text);
    await page.click('#composer button[type="submit"]');
    await new Promise((r) => setTimeout(r, 700));
}

async function messageIdForText(page, text) {
    return page.evaluate((t) => {
        const container = [...document.querySelectorAll('[data-message-id]')].find((el) => el.dataset.body === t);
        return container ? container.dataset.messageId : null;
    }, text);
}

test('message multi-select: bulk forward sends every selected message to the destination, in original order', async () => {
    const aliceBobUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    await ensureContacts(USERS.alice, USERS.carol);

    const alice = await loginAs(USERS.alice);
    const { page } = alice;

    // Make sure the Alice<->Carol conversation exists to forward into.
    await page.goto(absoluteUrl('/contacts'), { waitUntil: 'networkidle0' });
    const carolChatStartHref = await page.evaluate(() => {
        const link = [...document.querySelectorAll('a')].find((a) => a.textContent.includes('Browser Carol'));
        return link ? link.closest('li')?.querySelector('a[href*="/chat/start/"]')?.getAttribute('href') : null;
    });
    assert(carolChatStartHref, 'Alice should have Carol as a contact by now');
    await page.goto(absoluteUrl(carolChatStartHref), { waitUntil: 'networkidle0' });

    await page.goto(aliceBobUrl, { waitUntil: 'networkidle0' });
    const stamp = Date.now();
    const textA = `Multiselect forward A ${stamp}`;
    const textB = `Multiselect forward B ${stamp}`;
    await sendMessage(page, textA);
    await sendMessage(page, textB);

    const idA = await messageIdForText(page, textA);
    const idB = await messageIdForText(page, textB);
    assert(idA && idB, 'expected to find both sent messages by their body text');

    // Enter select mode via the kebab menu, check both messages.
    await page.click('#chat-menu-btn');
    await page.click('#message-select-btn');

    const barVisible = await page.evaluate(() => !document.getElementById('message-bulk-bar').classList.contains('hidden'));
    assert(barVisible, 'expected the message bulk bar to appear after choosing Select messages');

    await page.click(`[data-message-id="${idA}"] .message-select-checkbox`);
    await page.click(`[data-message-id="${idB}"] .message-select-checkbox`);

    const count = await page.$eval('#message-bulk-count', (el) => el.textContent);
    assert(count === '2', `expected 2 selected, got ${count}`);

    await page.click('#message-bulk-forward-btn');
    await new Promise((r) => setTimeout(r, 800));

    await page.evaluate(() => {
        const btn = [...document.querySelectorAll('#forward-picker button')].find((b) => b.textContent.includes('Browser Carol'));
        if (!btn) throw new Error('Carol not found in forward picker');
        btn.click();
    });
    await new Promise((r) => setTimeout(r, 1200));

    // Select mode should have closed itself after a successful bulk forward.
    const barHiddenAfter = await page.evaluate(() => document.getElementById('message-bulk-bar').classList.contains('hidden'));
    assert(barHiddenAfter, 'expected select mode to exit automatically after forwarding');

    await page.goto(absoluteUrl(carolChatStartHref), { waitUntil: 'networkidle0' });
    const carolMessagesText = await page.evaluate(() => document.getElementById('messages').innerText);
    const indexA = carolMessagesText.indexOf(textA);
    const indexB = carolMessagesText.indexOf(textB);
    assert(indexA !== -1 && indexB !== -1, `expected both forwarded messages in Alice<->Carol, got: ${carolMessagesText}`);
    assert(indexA < indexB, 'expected the forwarded messages to preserve their original send order');

    noErrors(alice, 'message multi-select forward');
    await closeSession(alice);
});

test('message multi-select: bulk delete removes every selected message for the deleter only, and Cancel discards the selection', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);
    const { page } = alice;

    await page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    const stamp = Date.now();
    const textA = `Multiselect delete A ${stamp}`;
    const textB = `Multiselect delete B ${stamp}`;
    await sendMessage(page, textA);
    await sendMessage(page, textB);

    const idA = await messageIdForText(page, textA);
    const idB = await messageIdForText(page, textB);

    await bob.page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    await new Promise((r) => setTimeout(r, 1200));

    // Cancel must not delete anything.
    await page.click('#chat-menu-btn');
    await page.click('#message-select-btn');
    await page.click(`[data-message-id="${idA}"] .message-select-checkbox`);
    await page.click('#message-bulk-cancel-btn');
    await page.reload({ waitUntil: 'networkidle0' });
    const stillThereAfterCancel = await page.evaluate((t) => document.getElementById('messages').innerText.includes(t), textA);
    assert(stillThereAfterCancel, 'expected Cancel to discard the selection without deleting anything');

    // Now actually bulk-delete both.
    await page.click('#chat-menu-btn');
    await page.click('#message-select-btn');
    await page.click(`[data-message-id="${idA}"] .message-select-checkbox`);
    await page.click(`[data-message-id="${idB}"] .message-select-checkbox`);
    // The delete confirmation is now the app's own themed MeetDialog
    // (an in-page modal), not a native confirm() - Puppeteer's
    // 'dialog' event never fires for it, so click its own OK button.
    await page.click('#message-bulk-delete-btn');
    await page.waitForSelector('#meet-dialog-overlay.flex');
    await page.click('#meet-dialog-ok');
    await new Promise((r) => setTimeout(r, 1000));

    const aliceText = await page.evaluate(() => document.getElementById('messages').innerText);
    assert(!aliceText.includes(textA) && !aliceText.includes(textB), 'expected alice to no longer see either bulk-deleted message');

    await bob.page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    const bobText = await bob.page.evaluate(() => document.getElementById('messages').innerText);
    assert(bobText.includes(textA) && bobText.includes(textB), 'expected bob to still see both messages - bulk delete must be delete-for-me only');

    noErrors(alice, 'message multi-select delete (alice)');
    noErrors(bob, 'message multi-select delete (bob)');
    await closeSession(alice);
    await closeSession(bob);
});
