import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, BASE_URL, absoluteUrl } from './support/session.js';

function noErrors(session, label) {
    assert(session.errors.length === 0, `${label} had JS errors: ${JSON.stringify(session.errors)}`);
}

test('login lands on the chat list with no JS errors', async () => {
    const alice = await loginAs(USERS.alice);
    assert(alice.page.url().endsWith('/chat'), `expected the chat list, got ${alice.page.url()}`);
    noErrors(alice, 'chat');
    await closeSession(alice);
});

test('profile page shows the logged-in user\'s name', async () => {
    const alice = await loginAs(USERS.alice);
    await alice.page.goto(`${BASE_URL}/profile`, { waitUntil: 'networkidle0' });
    const text = await alice.page.evaluate(() => document.body.innerText);
    assert(text.includes('Browser Alice'), 'profile page should show the user\'s full name');
    noErrors(alice, 'profile');
    await closeSession(alice);
});

test('contact request: send, accept, and appear as mutual contacts', async () => {
    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    await alice.page.goto(`${BASE_URL}/contacts?q=Browser+Bob`, { waitUntil: 'networkidle0' });
    const hasAddButton = await alice.page.evaluate(() => document.body.innerText.includes('Add contact'));
    if (hasAddButton) {
        await alice.page.evaluate(() => {
            const form = [...document.querySelectorAll('form')].find((f) =>
                f.action.includes('/contacts/request'),
            );
            form?.submit();
        });
        await new Promise((r) => setTimeout(r, 500));
    }

    await bob.page.goto(`${BASE_URL}/contacts/requests`, { waitUntil: 'networkidle0' });
    const bobText = await bob.page.evaluate(() => document.body.innerText);
    if (bobText.includes('Browser Alice') && bobText.includes('Accept')) {
        await bob.page.evaluate(() => {
            const forms = [...document.querySelectorAll('form')];
            const acceptForm = forms.find((f) => f.action.includes('/request/') && f.action.includes('/accept'));
            acceptForm?.submit();
        });
        await new Promise((r) => setTimeout(r, 500));
    }

    await alice.page.goto(`${BASE_URL}/contacts`, { waitUntil: 'networkidle0' });
    const aliceContactsText = await alice.page.evaluate(() => document.body.innerText);
    assert(aliceContactsText.includes('Browser Bob'), 'Alice should now see Bob as a contact');

    noErrors(alice, 'contacts (alice)');
    noErrors(bob, 'contacts (bob)');
    await closeSession(alice);
    await closeSession(bob);
});

test('1:1 chat: message sent by Alice is received by Bob exactly once (no duplication on poll)', async () => {
    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    // Resolve Bob's numeric user id from the contacts page link, then start a chat.
    await alice.page.goto(`${BASE_URL}/contacts`, { waitUntil: 'networkidle0' });
    const chatHref = await alice.page.evaluate(() => {
        const link = [...document.querySelectorAll('a')].find((a) => a.textContent.includes('Browser Bob'));
        return link ? link.closest('li')?.querySelector('a[href*="/chat/start/"]')?.getAttribute('href') : null;
    });

    if (chatHref) {
        await alice.page.goto(absoluteUrl(chatHref), { waitUntil: 'networkidle0' });
    } else {
        // Fall back: click the contact row itself, which links straight into chat.start.
        await alice.page.evaluate(() => {
            const link = [...document.querySelectorAll('a[href*="/chat/start/"]')][0];
            link?.click();
        });
        await alice.page.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {});
    }

    assert(alice.page.url().includes('/chat/'), `Alice should have landed in a chat thread, got ${alice.page.url()}`);
    const conversationUrl = alice.page.url();

    const uniqueMessage = `Hello Bob, unique token ${Date.now()}`;
    await alice.page.type('#composer-input', uniqueMessage);
    await alice.page.click('#composer button[type="submit"]');
    await new Promise((r) => setTimeout(r, 1000));

    const aliceOccurrences = await alice.page.evaluate(
        (msg) => document.getElementById('messages').innerText.split(msg).length - 1,
        uniqueMessage,
    );
    assert(aliceOccurrences === 1, `Alice's own view should show the message exactly once, saw ${aliceOccurrences}`);

    await bob.page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    await new Promise((r) => setTimeout(r, 1200));

    const bobOccurrencesFirst = await bob.page.evaluate(
        (msg) => document.getElementById('messages').innerText.split(msg).length - 1,
        uniqueMessage,
    );
    assert(bobOccurrencesFirst === 1, `Bob should see the message exactly once, saw ${bobOccurrencesFirst}`);

    // Let 3 more poll cycles (open_conversation_ms=1000 by default) pass and
    // confirm the count does NOT grow - this is the exact regression this
    // suite exists to catch (MessageResource missing id -> lastMessageId
    // stuck at 0 -> every poll re-fetches and re-appends everything).
    await new Promise((r) => setTimeout(r, 3500));

    const bobOccurrencesAfterPolling = await bob.page.evaluate(
        (msg) => document.getElementById('messages').innerText.split(msg).length - 1,
        uniqueMessage,
    );
    assert(
        bobOccurrencesAfterPolling === 1,
        `Message must not duplicate across poll cycles: saw ${bobOccurrencesAfterPolling} occurrences after waiting`,
    );

    const totalMessageNodesBefore = await bob.page.evaluate(() => document.getElementById('messages').children.length);
    await new Promise((r) => setTimeout(r, 2500));
    const totalMessageNodesAfter = await bob.page.evaluate(() => document.getElementById('messages').children.length);
    assert(
        totalMessageNodesBefore === totalMessageNodesAfter,
        `Message DOM node count must stay stable across polls (was ${totalMessageNodesBefore}, now ${totalMessageNodesAfter})`,
    );

    noErrors(alice, '1:1 chat (alice)');
    noErrors(bob, '1:1 chat (bob)');
    await closeSession(alice);
    await closeSession(bob);
});

test('presence: an active user shows as online to the other party', async () => {
    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    await bob.page.goto(`${BASE_URL}/dashboard`, { waitUntil: 'networkidle0' });
    await new Promise((r) => setTimeout(r, 500)); // let Bob's heartbeat fire

    await alice.page.goto(`${BASE_URL}/contacts`, { waitUntil: 'networkidle0' });
    const chatHref = await alice.page.evaluate(() => {
        const link = [...document.querySelectorAll('a[href*="/chat/start/"]')][0];
        return link ? link.getAttribute('href') : null;
    });
    assert(chatHref, 'Alice should have at least one contact to chat with');
    await alice.page.goto(absoluteUrl(chatHref), { waitUntil: 'networkidle0' });
    await new Promise((r) => setTimeout(r, 9000)); // conversation_list_ms default poll for presence in header

    const presenceText = await alice.page.evaluate(() => {
        const el = document.getElementById('presence-status');
        return el ? el.textContent.trim() : null;
    });
    assert(presenceText === 'Online', `expected Bob to show as online to Alice, got "${presenceText}"`);

    await closeSession(alice);
    await closeSession(bob);
});

test('group chat: create a group, send a message, member sees it under the group name', async () => {
    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    await alice.page.goto(`${BASE_URL}/groups/create`, { waitUntil: 'networkidle0' });
    await alice.page.type('#group_name', 'Browser Test Group');

    const bobUserId = await alice.page.evaluate(() => {
        const checkbox = [...document.querySelectorAll('input[name="members[]"]')].find((cb) => {
            const label = cb.closest('label');
            return label && label.textContent.includes('Browser Bob');
        });
        return checkbox ? checkbox.value : null;
    });
    assert(bobUserId, 'Bob must be selectable as a group member (must be an existing contact of Alice)');

    await alice.page.evaluate((id) => {
        const checkbox = document.querySelector(`input[name="members[]"][value="${id}"]`);
        checkbox.checked = true;
    }, bobUserId);

    await Promise.all([
        alice.page.waitForNavigation({ waitUntil: 'networkidle0' }),
        // Not page.click() - a real coordinate click on this form's
        // submit button can land on the fixed mobile bottom nav bar
        // instead (it overlaps near the viewport bottom at Puppeteer's
        // default small viewport size). Submitting the form directly
        // sidesteps that entirely.
        alice.page.evaluate(() => document.querySelector('form[action$="/groups"]').submit()),
    ]);
    assert(alice.page.url().includes('/groups/'), `expected to land on the group page, got ${alice.page.url()}`);

    const groupPageText = await alice.page.evaluate(() => document.body.innerText);
    assert(groupPageText.includes('Browser Test Group'), 'group page should show the group name');

    const groupChatHref = await alice.page.evaluate(() => {
        const link = [...document.querySelectorAll('a')].find((a) => a.textContent.trim() === 'Open chat');
        return link ? link.getAttribute('href') : null;
    });
    assert(groupChatHref, 'group page should have an "Open chat" link');

    await alice.page.goto(absoluteUrl(groupChatHref), { waitUntil: 'networkidle0' });
    const headerText = await alice.page.evaluate(() => document.querySelector('.truncate.text-sm.font-semibold')?.textContent);
    assert(headerText?.includes('Browser Test Group'), `chat header should show the group name, got "${headerText}"`);

    const groupMessage = `Group hello ${Date.now()}`;
    await alice.page.type('#composer-input', groupMessage);
    await alice.page.click('#composer button[type="submit"]');
    await new Promise((r) => setTimeout(r, 800));

    await bob.page.goto(`${BASE_URL}/chat`, { waitUntil: 'networkidle0' });
    const bobListText = await bob.page.evaluate(() => document.body.innerText);
    assert(bobListText.includes('Browser Test Group'), 'Bob should see the group in his chat list, not an arbitrary member name');

    noErrors(alice, 'group chat (alice)');
    noErrors(bob, 'group chat (bob)');
    await closeSession(alice);
    await closeSession(bob);
});
