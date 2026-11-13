import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, absoluteUrl } from './support/session.js';
import { ensureConversationUrl } from './support/fixtures.js';

test('message ticks: single on send, double gray on delivery, unique amber on read, with an accurate Info view', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    const text = 'tick test ' + Date.now();
    await alice.page.type('#composer-input', text);
    await alice.page.click('#composer button[type="submit"]');
    await new Promise((r) => setTimeout(r, 1000));

    const tickStatus = async () => alice.page.evaluate((t) => {
        const bubble = [...document.querySelectorAll('[data-message-id]')].find((el) => el.textContent.includes(t));
        const tick = bubble ? bubble.querySelector('.message-status-tick') : null;
        return tick ? tick.dataset.status : null;
    }, text);

    assert((await tickStatus()) === 'sent', `expected a freshly sent message to show "sent" before Bob ever fetches it, got "${await tickStatus()}"`);

    // Bob's client merely syncing the chat LIST (not opening this
    // specific thread) is what marks it delivered - his phone/app has
    // received it, but he hasn't actually read this conversation yet.
    await bob.page.goto(absoluteUrl('/chat'), { waitUntil: 'networkidle0' });
    await new Promise((r) => setTimeout(r, 3000));

    assert((await tickStatus()) === 'delivered', `expected Alice's tick to advance to "delivered" once Bob's client synced the chat list (without opening the thread), got "${await tickStatus()}"`);

    // Only once Bob actually opens the conversation itself does it
    // become read.
    await bob.page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    await new Promise((r) => setTimeout(r, 3000));
    assert((await tickStatus()) === 'read', `expected Alice's tick to advance to "read" once Bob had the conversation open, got "${await tickStatus()}"`);

    const tickColor = await alice.page.evaluate((t) => {
        const bubble = [...document.querySelectorAll('[data-message-id]')].find((el) => el.textContent.includes(t));
        const svg = bubble.querySelector('.message-status-tick svg');
        return svg.getAttribute('class');
    }, text);
    assert(tickColor.includes('amber'), `expected the read tick to use a distinct amber color (not WhatsApp blue), got class "${tickColor}"`);

    // Open the Info view via the message's action row and confirm it
    // shows real delivered/read timestamps for Bob.
    await alice.page.evaluate((t) => {
        const bubble = [...document.querySelectorAll('[data-message-id]')].find((el) => el.textContent.includes(t));
        bubble.querySelector('.message-bubble').click();
    }, text);
    await new Promise((r) => setTimeout(r, 200));
    await alice.page.evaluate((t) => {
        const bubble = [...document.querySelectorAll('[data-message-id]')].find((el) => el.textContent.includes(t));
        const infoBtn = [...bubble.querySelectorAll('[data-action="info"]')][0];
        infoBtn.click();
    }, text);
    await new Promise((r) => setTimeout(r, 1000));

    const infoText = await alice.page.evaluate(() => document.getElementById('message-info-modal-body').textContent);
    assert(infoText.includes('Browser Bob'), `expected the Info view to list Bob as a recipient, got "${infoText}"`);
    assert(infoText.includes('Read at'), `expected the Info view to show a real "Read at" timestamp, got "${infoText}"`);

    assert(alice.errors.length === 0, `alice had JS errors: ${JSON.stringify(alice.errors)}`);
    assert(bob.errors.length === 0, `bob had JS errors: ${JSON.stringify(bob.errors)}`);

    await closeSession(alice);
    await closeSession(bob);
});
