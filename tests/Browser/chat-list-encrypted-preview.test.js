import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, absoluteUrl } from './support/session.js';
import { ensureConversationUrl } from './support/fixtures.js';

test('chat list: an encrypted last message shows real decrypted text, never raw ciphertext', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);

    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    const text = 'a real preview, not ciphertext ' + Date.now();
    await alice.page.type('#composer-input', text);
    await alice.page.click('#composer button[type="submit"]');
    await new Promise((r) => setTimeout(r, 1500));

    const conversationId = await alice.page.evaluate(() => document.getElementById('messages').dataset.conversationId);
    const raw = await alice.page.evaluate((id) => axios.get('/api/v1/conversations/' + id).then((r) => r.data.data.last_message), conversationId);
    assert(raw.encryption_mode === 'e2e', `expected the last message to genuinely be e2e, got "${raw.encryption_mode}"`);
    assert(raw.body !== text, 'expected the raw API body to be ciphertext, confirming this test actually exercises encryption');

    // Fresh page load of the chat LIST (not the thread) - this is
    // exactly where the bug was: the list rendered the raw ciphertext
    // directly with no decryption step at all.
    await alice.page.goto(absoluteUrl('/chat'), { waitUntil: 'networkidle0' });
    await new Promise((r) => setTimeout(r, 1000));

    const previewText = await alice.page.evaluate((id) => {
        const row = document.querySelector('[data-conversation-id="' + id + '"] .conversation-last-message');
        return row ? row.textContent.trim() : null;
    }, conversationId);

    assert(previewText === text, `expected the chat list preview to show the real decrypted text "${text}", got "${previewText}"`);
    assert(!previewText.includes(raw.body), 'expected the chat list preview to never contain the raw ciphertext');

    noErrors(alice, 'chat list encrypted preview');
    await closeSession(alice);
});

function noErrors(session, label) {
    assert(session.errors.length === 0, `${label} had JS errors: ${JSON.stringify(session.errors)}`);
}
