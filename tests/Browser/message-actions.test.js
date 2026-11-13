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

async function clickMessageAction(page, messageText, action) {
    await page.evaluate((text, action) => {
        const container = [...document.querySelectorAll('[data-message-id]')].find((el) => el.dataset.body === text);
        if (!container) throw new Error(`no message container found for text: ${text}`);
        const btn = container.querySelector(`[data-action="${action}"]`);
        if (!btn) throw new Error(`no action button "${action}" found for message: ${text}`);
        btn.click();
    }, messageText, action);
}

test('composer: voice button shows by default, swaps to Send the instant there is text, and back once the composer empties out', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    async function visibility() {
        return alice.page.evaluate(() => ({
            voiceHidden: document.getElementById('voice-note-button').classList.contains('hidden'),
            sendHidden: document.querySelector('#composer button[type="submit"]').classList.contains('hidden'),
        }));
    }

    const empty = await visibility();
    assert(!empty.voiceHidden && empty.sendHidden, `expected Voice visible and Send hidden with an empty composer, got ${JSON.stringify(empty)}`);

    await alice.page.type('#composer-input', 'hey there');
    const typed = await visibility();
    assert(typed.voiceHidden && !typed.sendHidden, `expected Send visible and Voice hidden once text is typed, got ${JSON.stringify(typed)}`);

    await alice.page.evaluate(() => { document.getElementById('composer-input').value = ''; document.getElementById('composer-input').dispatchEvent(new Event('input')); });
    const clearedAgain = await visibility();
    assert(!clearedAgain.voiceHidden && clearedAgain.sendHidden, `expected Voice visible again once the composer is cleared, got ${JSON.stringify(clearedAgain)}`);

    // Sending clears the composer, which must swap it back to Voice too.
    await alice.page.type('#composer-input', 'sent then check the toggle');
    await alice.page.click('#composer button[type="submit"]');
    await new Promise((r) => setTimeout(r, 600));
    const afterSend = await visibility();
    assert(!afterSend.voiceHidden && afterSend.sendHidden, `expected Voice visible again after sending, got ${JSON.stringify(afterSend)}`);

    noErrors(alice, 'composer voice/send toggle');
    await closeSession(alice);
});

test('URLs in a message render as real clickable links (opening in a new tab) and never overflow the bubble, even a long one with no spaces', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    const longUrl = 'https://example.com/a/very/long/path/segment/that/keeps/going/with-no-spaces-anywhere-in-it-at-all/index.html?query=abcdefghijklmnopqrstuvwxyz';
    const text = `Check this out: ${longUrl} thanks!`;
    await sendMessage(alice.page, text);

    const link = await alice.page.evaluate((t) => {
        const container = [...document.querySelectorAll('[data-message-id]')].find((el) => el.dataset.body === t);
        const a = container ? container.querySelector('.message-bubble a') : null;
        return a ? { href: a.getAttribute('href'), target: a.getAttribute('target'), rel: a.getAttribute('rel'), text: a.textContent } : null;
    }, text);

    assert(link, 'expected the URL to render as a real <a> element inside the message bubble');
    assert(link.href === longUrl, `expected the link's href to be the exact URL, got "${link.href}"`);
    assert(link.target === '_blank', `expected the link to open in a new tab, got target="${link.target}"`);
    assert(link.rel && link.rel.includes('noopener'), `expected rel="noopener ..." on the link, got "${link.rel}"`);
    assert(link.text === longUrl, 'expected the visible link text to be the real URL, not mangled/truncated');

    const overflow = await alice.page.evaluate((t) => {
        const container = [...document.querySelectorAll('[data-message-id]')].find((el) => el.dataset.body === t);
        const bubble = container.querySelector('.message-bubble');
        return bubble.scrollWidth - bubble.clientWidth;
    }, text);
    assert(overflow <= 1, `expected the bubble to never overflow horizontally even with a long, space-free URL, got a ${overflow}px overflow`);

    noErrors(alice, 'URL linkify/wrap');
    await closeSession(alice);
});

test('react: clicking an emoji adds a reaction badge visible after reload', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    const text = `React target ${Date.now()}`;
    await sendMessage(alice.page, text);

    // The emoji reaction strip lives across the top of the floating
    // actions card (WhatsApp-style) rather than behind a separate
    // "React" toggle - clicking straight through, same as every other
    // action button, without needing to actually open the card first.
    await alice.page.evaluate((text) => {
        const container = [...document.querySelectorAll('[data-message-id]')].find((el) => el.dataset.body === text);
        const emojiBtn = [...container.querySelectorAll('[data-action="react"]')].find((b) => b.dataset.emoji === '👍');
        emojiBtn.click();
    }, text);
    await new Promise((r) => setTimeout(r, 1000));

    const hasReactionBadge = await alice.page.evaluate((text) => {
        const container = [...document.querySelectorAll('[data-message-id]')].find((el) => el.dataset.body === text);
        return container ? container.innerText.includes('👍') : false;
    }, text);
    assert(hasReactionBadge, 'expected a 👍 reaction badge to appear on the message after reacting');

    // Tapping the SAME emoji again removes it (un-react) - the button
    // should now be flagged active, and clicking it deletes the reaction
    // rather than re-adding it.
    const wasActive = await alice.page.evaluate((text) => {
        const container = [...document.querySelectorAll('[data-message-id]')].find((el) => el.dataset.body === text);
        const emojiBtn = [...container.querySelectorAll('[data-action="react"]')].find((b) => b.dataset.emoji === '👍');
        return emojiBtn.dataset.active;
    }, text);
    assert(wasActive === 'true', `expected the 👍 emoji button to be flagged active after reacting with it, got dataset.active="${wasActive}"`);

    await alice.page.evaluate((text) => {
        const container = [...document.querySelectorAll('[data-message-id]')].find((el) => el.dataset.body === text);
        const emojiBtn = [...container.querySelectorAll('[data-action="react"]')].find((b) => b.dataset.emoji === '👍');
        emojiBtn.click();
    }, text);
    await new Promise((r) => setTimeout(r, 1000));

    const hasReactionBadgeAfterUnreact = await alice.page.evaluate((text) => {
        const container = [...document.querySelectorAll('[data-message-id]')].find((el) => el.dataset.body === text);
        return container ? container.innerText.includes('👍') : false;
    }, text);
    assert(!hasReactionBadgeAfterUnreact, 'expected the 👍 reaction badge to disappear after tapping the same emoji again to un-react');

    noErrors(alice, 'react');
    await closeSession(alice);
});

test('reply: replying sets a preview banner and the sent reply shows a quoted preview', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    const originalText = `Original message ${Date.now()}`;
    await sendMessage(alice.page, originalText);

    await clickMessageAction(alice.page, originalText, 'reply');
    const bannerVisible = await alice.page.evaluate(() => !document.getElementById('reply-banner').classList.contains('hidden'));
    assert(bannerVisible, 'reply banner should become visible after clicking Reply');

    const replyText = `Reply text ${Date.now()}`;
    await sendMessage(alice.page, replyText);
    await new Promise((r) => setTimeout(r, 500));

    const bannerHiddenAfterSend = await alice.page.evaluate(() => document.getElementById('reply-banner').classList.contains('hidden'));
    assert(bannerHiddenAfterSend, 'reply banner should be cleared after sending the reply');

    const replyHasQuote = await alice.page.evaluate((text) => {
        const container = [...document.querySelectorAll('[data-message-id]')].find((el) => el.dataset.body === text);
        return container ? container.textContent.includes('Original message') : false;
    }, replyText);
    assert(replyHasQuote, 'the sent reply should render a quoted preview of the original message');

    noErrors(alice, 'reply');
    await closeSession(alice);
});

test('copy: copying a message calls the Clipboard API with its real (decrypted) text', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);

    // Headless Chrome's clipboard permission grants are unreliable
    // under CI automation regardless of overridePermissions (denied
    // even when explicitly granted) - stubbing navigator.clipboard here
    // tests that OUR code calls the real Clipboard API with the real,
    // already-decrypted text, without depending on the browser's own
    // clipboard sink actually working under headless automation.
    await alice.page.evaluateOnNewDocument(() => {
        window.__copiedText = null;
        Object.defineProperty(navigator, 'clipboard', {
            value: { writeText: function (text) { window.__copiedText = text; return Promise.resolve(); } },
            configurable: true,
        });
    });
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    const text = `Copy target ${Date.now()}`;
    await sendMessage(alice.page, text);

    await clickMessageAction(alice.page, text, 'copy');
    await new Promise((r) => setTimeout(r, 300));

    const copiedText = await alice.page.evaluate(() => window.__copiedText);
    assert(copiedText === text, `expected the Copy action to call navigator.clipboard.writeText with the real text "${text}", got "${copiedText}"`);

    noErrors(alice, 'copy');
    await closeSession(alice);
});

test('star and pin: toggling updates the action label after a reload', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    const text = `Star and pin target ${Date.now()}`;
    await sendMessage(alice.page, text);

    await clickMessageAction(alice.page, text, 'star');
    await new Promise((r) => setTimeout(r, 1000));
    const starLabelAfter = await alice.page.evaluate((text) => {
        const container = [...document.querySelectorAll('[data-message-id]')].find((el) => el.dataset.body === text);
        return container?.querySelector('[data-action="star"]')?.textContent;
    }, text);
    assert(starLabelAfter.includes('Unstar'), `expected the button to flip to "Unstar", got "${starLabelAfter}"`);

    await clickMessageAction(alice.page, text, 'pin');
    await new Promise((r) => setTimeout(r, 1000));
    const pinned = await alice.page.evaluate(() => {
        return axios.get('/api/v1/conversations/' + document.getElementById('messages').dataset.conversationId + '/pinned')
            .then((r) => r.data.data.length > 0);
    });
    assert(pinned, 'expected at least one pinned message after pinning');

    const starredMessageId = await alice.page.evaluate((text) => {
        const el = [...document.querySelectorAll('[data-message-id]')].find((n) => n.dataset.body === text);
        return el?.dataset.messageId;
    }, text);
    assert(starredMessageId, 'expected to find the message id of the message just starred');

    // WhatsApp-parity "Starred Messages" page - the star action itself
    // predates this batch; this is the previously-missing dedicated view.
    // Messages are end-to-end encrypted automatically now, and the
    // server can never decrypt them - this server-rendered page shows
    // a placeholder instead of raw ciphertext (same treatment as chat
    // export), so the row is found by id, not by its plaintext.
    await alice.page.goto(absoluteUrl('/chat/starred'), { waitUntil: 'networkidle0' });
    const starredRow = await alice.page.evaluate((id) => {
        const li = document.querySelector(`[data-starred-message-id="${id}"]`);
        return li ? li.textContent : null;
    }, starredMessageId);
    assert(starredRow, 'expected the starred row for this exact message to appear on /chat/starred');
    assert(starredRow.includes('End-to-end encrypted'), `expected a placeholder, not raw ciphertext, got: ${starredRow}`);

    const unstarredMessageId = starredMessageId;

    await alice.page.click(`button[data-unstar-message-id="${unstarredMessageId}"]`);
    await new Promise((r) => setTimeout(r, 500));
    const stillThere = await alice.page.evaluate((id) => !!document.querySelector(`[data-starred-message-id="${id}"]`), unstarredMessageId);
    assert(!stillThere, 'expected this specific message row to disappear from /chat/starred after clicking Unstar');

    noErrors(alice, 'star/pin');
    await closeSession(alice);
});

test('pinned-message banner: appears live for both participants, jump-to and unpin work, and pinning a new message replaces the old one', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    await bob.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    const firstText = `Pin banner first ${Date.now()}`;
    await sendMessage(alice.page, firstText);
    await clickMessageAction(alice.page, firstText, 'pin');

    async function bannerText(page) {
        return page.evaluate(() => {
            const banner = document.getElementById('pinned-banner');
            if (banner.classList.contains('hidden')) return null;
            return document.getElementById('pinned-banner-text').textContent;
        });
    }
    async function waitForBanner(page, expectedSubstring, label) {
        // conversation_list_ms (the poll this banner piggybacks on)
        // defaults to 8s - give a full extra cycle of headroom rather
        // than racing the exact boundary.
        const deadline = Date.now() + 13000;
        let last = null;
        while (Date.now() < deadline) {
            last = await bannerText(page);
            if (last && last.includes(expectedSubstring)) return;
            await new Promise((r) => setTimeout(r, 300));
        }
        throw new Error(`${label}: expected pinned banner to contain "${expectedSubstring}", last saw "${last}"`);
    }

    // Alice pinned it herself - shows immediately, no reload.
    await waitForBanner(alice.page, firstText, 'alice sees her own pin instantly');
    // Bob never reloaded either - his page must pick this up on its own
    // periodic poll, not require a refresh.
    await waitForBanner(bob.page, firstText, 'bob sees alice\'s pin without reloading');

    // Pinning a second message replaces the first, for both of them.
    const secondText = `Pin banner second ${Date.now()}`;
    await sendMessage(bob.page, secondText);
    await clickMessageAction(bob.page, secondText, 'pin');

    await waitForBanner(alice.page, secondText, 'alice sees the replacement pin');
    await waitForBanner(bob.page, secondText, 'bob sees his own replacement pin instantly');

    // Clicking the banner (not the × ) jumps to and briefly highlights
    // the actual message.
    await alice.page.click('#pinned-banner');
    await new Promise((r) => setTimeout(r, 200));
    const highlighted = await alice.page.evaluate((text) => {
        const container = [...document.querySelectorAll('[data-message-id]')].find((el) => el.dataset.body === text);
        return container ? container.classList.contains('ring-2') : false;
    }, secondText);
    assert(highlighted, 'expected clicking the pinned banner to highlight the pinned message');

    // Unpinning from the banner's × clears it for everyone.
    await alice.page.click('#pinned-banner-unpin');
    await new Promise((r) => setTimeout(r, 500));
    const aliceBannerGone = await alice.page.evaluate(() => document.getElementById('pinned-banner').classList.contains('hidden'));
    assert(aliceBannerGone, 'expected the banner to disappear immediately for alice after unpinning from it');

    await new Promise((r) => setTimeout(r, 9000));
    const bobBannerGone = await bob.page.evaluate(() => document.getElementById('pinned-banner').classList.contains('hidden'));
    assert(bobBannerGone, 'expected bob\'s banner to clear too, without reloading');

    noErrors(alice, 'pinned banner (alice)');
    noErrors(bob, 'pinned banner (bob)');
    await closeSession(alice);
    await closeSession(bob);
});

test('delete for me: message disappears for the deleter only', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    const text = `Delete for me target ${Date.now()}`;
    await sendMessage(alice.page, text);

    await bob.page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    await new Promise((r) => setTimeout(r, 1200));

    alice.page.once('dialog', (d) => d.accept());
    await clickMessageAction(alice.page, text, 'delete-me');
    await new Promise((r) => setTimeout(r, 1000));

    const aliceStillSeesIt = await alice.page.evaluate(
        (t) => document.getElementById('messages').innerText.includes(t),
        text,
    );
    assert(!aliceStillSeesIt, 'Alice should no longer see a message she deleted for herself');

    await bob.page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    const bobStillSeesIt = await bob.page.evaluate(
        (t) => document.getElementById('messages').innerText.includes(t),
        text,
    );
    assert(bobStillSeesIt, 'Bob should still see the message - delete for me must not delete it for the other party');

    noErrors(alice, 'delete-for-me (alice)');
    noErrors(bob, 'delete-for-me (bob)');
    await closeSession(alice);
    await closeSession(bob);
});

test('forward: forwarding a message to another conversation delivers it there', async () => {
    const aliceBobUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    await ensureContacts(USERS.alice, USERS.carol);

    const alice = await loginAs(USERS.alice);

    // Make sure the Alice<->Carol conversation exists (contacts != conversations).
    await alice.page.goto(absoluteUrl('/contacts'), { waitUntil: 'networkidle0' });
    const carolChatStartHref = await alice.page.evaluate(() => {
        const link = [...document.querySelectorAll('a')].find((a) => a.textContent.includes('Browser Carol'));
        return link ? link.closest('li')?.querySelector('a[href*="/chat/start/"]')?.getAttribute('href') : null;
    });
    assert(carolChatStartHref, 'Alice should have Carol as a contact by now');
    await alice.page.goto(absoluteUrl(carolChatStartHref), { waitUntil: 'networkidle0' });

    await alice.page.goto(aliceBobUrl, { waitUntil: 'networkidle0' });
    const text = `Forward target ${Date.now()}`;
    await sendMessage(alice.page, text);

    await clickMessageAction(alice.page, text, 'forward');
    await new Promise((r) => setTimeout(r, 800));

    await alice.page.evaluate(() => {
        const btn = [...document.querySelectorAll('#forward-picker button')].find((b) => b.textContent.includes('Browser Carol'));
        if (!btn) throw new Error('Carol not found in forward picker - expected an Alice<->Carol conversation to exist');
        btn.click();
    });
    await new Promise((r) => setTimeout(r, 1000));

    await alice.page.goto(absoluteUrl(carolChatStartHref), { waitUntil: 'networkidle0' });
    const forwardedVisible = await alice.page.evaluate(
        (t) => document.getElementById('messages').innerText.includes(t),
        text,
    );
    assert(forwardedVisible, 'the forwarded message should appear in the Alice<->Carol conversation');

    noErrors(alice, 'forward');
    await closeSession(alice);
});

test('mute and archive: toggling updates the header button label and the chat list', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    // Read the current mute state rather than assuming a fresh/unmuted
    // start - this test may run after other tests that share the same
    // Alice<->Bob conversation. The mute action now lives as a text
    // menu item inside the header's kebab-menu dropdown (WhatsApp
    // feature-parity pass), not a standalone header button.
    const wasMuted = await alice.page.evaluate(() => {
        const form = [...document.querySelectorAll('form')].find((f) => f.action.includes('/mute'));
        return form?.querySelector('button')?.textContent.trim() === 'Unmute';
    });

    await Promise.all([
        alice.page.waitForNavigation({ waitUntil: 'networkidle0' }),
        alice.page.evaluate(() => {
            const form = [...document.querySelectorAll('form')].find((f) => f.action.includes('/mute'));
            form.submit();
        }),
    ]);
    const muteLabel = await alice.page.evaluate(() => {
        const form = [...document.querySelectorAll('form')].find((f) => f.action.includes('/mute'));
        return form?.querySelector('button')?.textContent.trim();
    });
    const expectedLabel = wasMuted ? 'Mute' : 'Unmute';
    assert(muteLabel === expectedLabel, `expected mute menu item to say "${expectedLabel}", got "${muteLabel}"`);

    if (expectedLabel === 'Unmute') {
        await alice.page.goto(absoluteUrl('/chat'), { waitUntil: 'networkidle0' });
        const showsMutedIcon = await alice.page.evaluate(() => document.querySelector('[title="Muted"]') !== null);
        assert(showsMutedIcon, 'chat list should indicate the conversation is muted');
        await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    }

    const wasArchived = await alice.page.evaluate(() => document.body.innerText.includes('Unarchive'));
    await Promise.all([
        alice.page.waitForNavigation({ waitUntil: 'networkidle0' }),
        alice.page.evaluate(() => {
            const form = [...document.querySelectorAll('form')].find((f) => f.action.includes('/archive'));
            form.submit();
        }),
    ]);

    // The kebab-menu label/hidden-input must flip after toggling, not stay
    // hardcoded to "Archive" - a hardcoded form would silently re-archive
    // on every submit instead of ever unarchiving.
    const expectedArchiveLabel = wasArchived ? 'Archive' : 'Unarchive';
    const archiveLabel = await alice.page.evaluate(() => {
        const form = [...document.querySelectorAll('form')].find((f) => f.action.includes('/archive'));
        return form?.querySelector('button')?.textContent.trim();
    });
    assert(archiveLabel === expectedArchiveLabel, `expected archive menu item to say "${expectedArchiveLabel}" after toggling, got "${archiveLabel}"`);

    await alice.page.goto(absoluteUrl('/chat/archived'), { waitUntil: 'networkidle0' });
    const inArchivedList = await alice.page.evaluate(() => document.querySelectorAll('[data-conversation-uuid]').length);
    if (!wasArchived) {
        assert(inArchivedList > 0, 'archived conversation should appear in the Archived chats view');
    } else {
        assert(inArchivedList === 0, 'conversation should have left the Archived chats view after unarchiving');
    }

    // Toggle back to the original state, and confirm the label flips again
    // (proves the form genuinely round-trips both directions, not just once).
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    await Promise.all([
        alice.page.waitForNavigation({ waitUntil: 'networkidle0' }),
        alice.page.evaluate(() => {
            const form = [...document.querySelectorAll('form')].find((f) => f.action.includes('/archive'));
            form.submit();
        }),
    ]);
    const restoredLabel = await alice.page.evaluate(() => {
        const form = [...document.querySelectorAll('form')].find((f) => f.action.includes('/archive'));
        return form?.querySelector('button')?.textContent.trim();
    });
    assert(restoredLabel === (wasArchived ? 'Unarchive' : 'Archive'), `expected archive menu item to flip back to "${wasArchived ? 'Unarchive' : 'Archive'}", got "${restoredLabel}"`);

    noErrors(alice, 'mute/archive');
    await closeSession(alice);
});

test('wallpaper: picking a swatch from the kebab menu applies and persists it', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.carol);
    const alice = await loginAs(USERS.alice);
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    await alice.page.click('#chat-menu-btn');
    await alice.page.click('#chat-wallpaper-btn');

    await new Promise((r) => setTimeout(r, 500));
    const swatchCount = await alice.page.$$eval('#wallpaper-swatches button', (els) => els.length);
    assert(swatchCount >= 2, `expected at least 2 wallpaper swatches, got ${swatchCount}`);

    const pickedColor = await alice.page.evaluate(() => {
        const swatch = document.querySelectorAll('#wallpaper-swatches button')[1];
        const color = swatch.style.backgroundColor;
        swatch.click();
        return color;
    });

    await new Promise((r) => setTimeout(r, 500));
    const appliedColor = await alice.page.$eval('#messages', (el) => el.style.backgroundColor);
    assert(appliedColor === pickedColor, `expected #messages background to become ${pickedColor}, got ${appliedColor}`);

    // Persisted server-side, not just a local style flip - reload and confirm.
    await alice.page.reload({ waitUntil: 'networkidle0' });
    await new Promise((r) => setTimeout(r, 500));
    const persistedColor = await alice.page.$eval('#messages', (el) => el.style.backgroundColor);
    assert(persistedColor === pickedColor, `expected the wallpaper to persist after reload, got ${persistedColor}`);

    noErrors(alice, 'wallpaper');
    await closeSession(alice);
});

test('mobile viewport: the floating actions card only opens via long-press (a plain tap does not open it, and does not fight a One View placeholder)', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    await alice.page.setViewport({ width: 375, height: 667 });

    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    const text = `Mobile visibility target ${Date.now()}`;
    await sendMessage(alice.page, text);

    async function actionsMenuDisplay() {
        return alice.page.evaluate((t) => {
            const container = [...document.querySelectorAll('[data-message-id]')].find((el) => el.dataset.body === t);
            const actions = container?.querySelector('.message-actions-menu');
            return actions ? getComputedStyle(actions).display : null;
        }, text);
    }

    async function bubbleBox() {
        return alice.page.evaluate((t) => {
            const container = [...document.querySelectorAll('[data-message-id]')].find((el) => el.dataset.body === t);
            const rect = container.querySelector('.message-bubble').getBoundingClientRect();
            return { x: rect.x + rect.width / 2, y: rect.y + rect.height / 2 };
        }, text);
    }

    async function longPress() {
        const box = await bubbleBox();
        const client = await alice.page.createCDPSession();
        await client.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: box.x, y: box.y }] });
        await new Promise((r) => setTimeout(r, 650)); // past the 500ms hold threshold
        await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    }

    const beforeTap = await actionsMenuDisplay();
    assert(beforeTap === 'none', `expected the floating actions card to be hidden by default at mobile width, got display: ${beforeTap}`);

    // A plain (quick) tap must NOT open it - this is what previously
    // fought with a One View placeholder's own "tap to view" behavior.
    const box = await bubbleBox();
    await alice.page.touchscreen.tap(box.x, box.y);
    await new Promise((r) => setTimeout(r, 200));
    const afterPlainTap = await actionsMenuDisplay();
    assert(afterPlainTap === 'none', `expected a plain tap NOT to open the floating actions card, got display: ${afterPlainTap}`);

    // A real long-press (touch held past the hold threshold) does open it.
    await longPress();
    const afterLongPress = await actionsMenuDisplay();
    assert(afterLongPress !== 'none', `expected a long-press to reveal the floating actions card, got display: ${afterLongPress}`);

    // It's a real floating card - position:fixed, positioned near the
    // bubble rather than pushed into the document flow below it.
    const position = await alice.page.evaluate((t) => {
        const container = [...document.querySelectorAll('[data-message-id]')].find((el) => el.dataset.body === t);
        return getComputedStyle(container.querySelector('.message-actions-menu')).position;
    }, text);
    assert(position === 'fixed', `expected the actions card to be a floating (position: fixed) element, got position: ${position}`);

    await longPress();
    const afterSecondTap = await actionsMenuDisplay();
    assert(afterSecondTap === 'none', `expected a second long-press to hide the floating actions card, got display: ${afterSecondTap}`);

    noErrors(alice, 'mobile visibility');
    await closeSession(alice);
});

test('swipe-right on a message bubble triggers Reply directly, as a shortcut', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    const { page } = alice;
    await page.setViewport({ width: 375, height: 667 });

    await page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    const text = `Swipe reply target ${Date.now()}`;
    await sendMessage(page, text);

    const box = await page.evaluate((t) => {
        const container = [...document.querySelectorAll('[data-message-id]')].find((el) => el.dataset.body === t);
        const rect = container.querySelector('.message-bubble').getBoundingClientRect();
        return { x: rect.x, y: rect.y, width: rect.width, height: rect.height };
    }, text);

    var client = await page.createCDPSession();
    var startX = box.x + 10;
    var y = box.y + box.height / 2;
    await client.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: startX, y: y }] });
    await client.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: startX + 90, y: y }] });
    await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });

    const bannerVisible = await page.evaluate(() => !document.getElementById('reply-banner').classList.contains('hidden'));
    assert(bannerVisible, 'expected a right-swipe on the bubble to open the reply banner directly');

    const bannerText = await page.evaluate(() => document.getElementById('reply-banner-text').textContent);
    assert(bannerText.includes(text), `expected the reply banner to reference the swiped message, got: ${bannerText}`);

    noErrors(alice, 'swipe to reply');
    await closeSession(alice);
});

test('disappearing messages: turning it on from the kebab menu makes a new message expire, and it stays off by default', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.carol);
    const alice = await loginAs(USERS.alice);
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    await alice.page.click('#chat-menu-btn');
    await alice.page.click('#chat-disappearing-btn');
    await new Promise((r) => setTimeout(r, 300));

    const optionLabels = await alice.page.$$eval('#disappearing-options button', (els) => els.map((el) => el.textContent.trim()));
    assert(JSON.stringify(optionLabels) === JSON.stringify(['Off', '24 hours', '7 days', '90 days', 'After being seen']), `expected the 5 duration options, got: ${JSON.stringify(optionLabels)}`);

    const initiallyActive = await alice.page.$eval('#disappearing-options button', (el) => el.textContent.trim());
    assert(initiallyActive === 'Off', `expected "Off" to be the default active option, got ${initiallyActive}`);

    await alice.page.evaluate(() => {
        [...document.querySelectorAll('#disappearing-options button')].find((b) => b.textContent.trim() === '24 hours').click();
    });
    await new Promise((r) => setTimeout(r, 500));

    const conversationId = await alice.page.$eval('#messages', (el) => el.dataset.conversationId);
    const setting = await alice.page.evaluate((id) => window.axios.get('/api/v1/conversations/' + id).then((r) => r.data.data.disappearing_messages_seconds), conversationId);
    assert(setting === 86400, `expected disappearing_messages_seconds to persist as 86400, got ${setting}`);

    await alice.page.type('#composer-input', 'this one should expire');
    await alice.page.click('#composer button[type="submit"]');
    await new Promise((r) => setTimeout(r, 800));

    // Messages are end-to-end encrypted automatically now, so the just-
    // sent message can't be found by matching its (now-ciphertext) body -
    // it's simply the last message in the thread.
    const expiresAt = await alice.page.evaluate((id) => window.axios.get('/api/v1/messages/' + id).then((r) => {
        const last = r.data.data[r.data.data.length - 1];
        return last ? last.expires_at : null;
    }), conversationId);
    assert(expiresAt, 'expected the newly sent message to carry a non-null expires_at once disappearing messages is on');

    noErrors(alice, 'disappearing messages');
    await closeSession(alice);
});

test('disappearing messages: "after being seen" stays visibly selected right after picking it, not just after a reload', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.carol);
    const alice = await loginAs(USERS.alice);
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    await alice.page.click('#chat-menu-btn');
    await alice.page.click('#chat-disappearing-btn');
    await new Promise((r) => setTimeout(r, 300));

    await alice.page.evaluate(() => {
        [...document.querySelectorAll('#disappearing-options button')].find((b) => b.textContent.trim() === 'After being seen').click();
    });
    await new Promise((r) => setTimeout(r, 500));

    const conversationId = await alice.page.$eval('#messages', (el) => el.dataset.conversationId);
    const setting = await alice.page.evaluate((id) => window.axios.get('/api/v1/conversations/' + id).then((r) => r.data.data.disappearing_messages_seconds), conversationId);
    assert(setting === 0, `expected disappearing_messages_seconds to persist as 0 (the "after seen" sentinel), got ${setting}`);

    // Re-open the picker without reloading - a falsy-zero bug in the
    // click handler (`seconds || ''`) used to blank out a real 0 back to
    // "off" in the DOM immediately after picking it, only self-correcting
    // on the next full page load. #chat-disappearing-btn closes the
    // kebab dropdown it lives in as soon as it's clicked once (see
    // show.blade.php), so the dropdown has to be reopened first or this
    // second click lands on a hidden, non-interactable element.
    await alice.page.click('#chat-menu-btn');
    await new Promise((r) => setTimeout(r, 200));
    await alice.page.click('#chat-disappearing-btn');
    await new Promise((r) => setTimeout(r, 300));
    const activeAfterPicking = await alice.page.$$eval('#disappearing-options button', (els) => {
        const active = els.find((el) => el.className.indexOf('border-brand-600') !== -1);
        return active ? active.textContent.trim() : null;
    });
    assert(activeAfterPicking === 'After being seen', `expected "After being seen" to read as the active option without a reload, got ${activeAfterPicking}`);

    noErrors(alice, 'disappearing messages after seen');
    await closeSession(alice);
});

test('disappearing messages: a persistent header indicator shows the current state, live for the other participant too', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.carol);
    const alice = await loginAs(USERS.alice);
    const carol = await loginAs(USERS.carol);
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    await carol.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    async function indicator(page) {
        return page.evaluate(() => {
            const el = document.getElementById('disappearing-indicator');
            const label = document.getElementById('disappearing-indicator-label');
            return { hidden: el.classList.contains('hidden'), label: label.textContent };
        });
    }

    // This alice<->carol conversation is a shared fixture other tests
    // in this file also toggle disappearing messages on - explicitly
    // reset to Off first rather than assume a clean starting state.
    await alice.page.click('#chat-menu-btn');
    await alice.page.click('#chat-disappearing-btn');
    await new Promise((r) => setTimeout(r, 300));
    await alice.page.evaluate(() => {
        [...document.querySelectorAll('#disappearing-options button')].find((b) => b.textContent.trim() === 'Off').click();
    });
    await new Promise((r) => setTimeout(r, 500));

    const beforeAlice = await indicator(alice.page);
    assert(beforeAlice.hidden, `expected the indicator hidden once reset to Off, got ${JSON.stringify(beforeAlice)}`);

    await alice.page.click('#chat-menu-btn');
    await alice.page.click('#chat-disappearing-btn');
    await new Promise((r) => setTimeout(r, 300));
    await alice.page.evaluate(() => {
        [...document.querySelectorAll('#disappearing-options button')].find((b) => b.textContent.trim() === '7 days').click();
    });
    await new Promise((r) => setTimeout(r, 500));

    const afterAlice = await indicator(alice.page);
    assert(!afterAlice.hidden && afterAlice.label === '7 days', `expected the indicator to show "7 days" immediately for the acting user, got ${JSON.stringify(afterAlice)}`);

    // Carol's page never touched the picker - only the next
    // pollConversationMeta() tick (conversation_list_ms) can tell her
    // client this changed, which is exactly the live-sync path being
    // tested here (not a reload).
    await new Promise((r) => setTimeout(r, 9000));
    const carolSees = await indicator(carol.page);
    assert(!carolSees.hidden && carolSees.label === '7 days', `expected the indicator to reach carol live (no reload) showing "7 days", got ${JSON.stringify(carolSees)}`);

    // Turning it back off must hide the indicator again, not just
    // relabel it.
    await alice.page.click('#chat-menu-btn');
    await new Promise((r) => setTimeout(r, 200));
    await alice.page.click('#chat-disappearing-btn');
    await new Promise((r) => setTimeout(r, 300));
    await alice.page.evaluate(() => {
        [...document.querySelectorAll('#disappearing-options button')].find((b) => b.textContent.trim() === 'Off').click();
    });
    await new Promise((r) => setTimeout(r, 500));
    const afterOff = await indicator(alice.page);
    assert(afterOff.hidden, `expected the indicator to hide again once turned off, got ${JSON.stringify(afterOff)}`);

    noErrors(alice, 'disappearing indicator (alice)');
    noErrors(carol, 'disappearing indicator (carol)');
    await closeSession(alice);
    await closeSession(carol);
});

test('push-triggered refresh: a message push event fires an immediate poll instead of waiting for the next 1000ms tick', async () => {
    // Halving the poll interval (1000ms -> 500ms) was tried already and
    // reverted - it doubled per-tab request volume and overloaded the
    // server. show.blade.php now listens for a 'meet-push-message'
    // CustomEvent (dispatched by push-notifications.js's Firebase
    // foreground onMessage() listener whenever a real push for this
    // conversation lands) and polls immediately on it, on top of the
    // unchanged interval. Real FCM credentials aren't available in this
    // environment, so this dispatches the same event directly rather
    // than going through actual Firebase - the consuming side (the
    // listener added in show.blade.php) is what's under test.
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    const conversationId = await alice.page.$eval('#messages', (el) => el.dataset.conversationId);

    const requestTimes = [];
    alice.page.on('request', (req) => {
        if (req.method() === 'GET' && req.url().includes('/api/v1/messages/' + conversationId)) {
            requestTimes.push(Date.now());
        }
    });

    // Let at least one normal interval tick pass first, so the ONLY
    // thing measured afterward is the event-triggered request, not a
    // coincidental regular one.
    await new Promise((r) => setTimeout(r, 1200));
    requestTimes.length = 0;

    const dispatchTime = Date.now();
    await alice.page.evaluate((id) => {
        window.dispatchEvent(new CustomEvent('meet-push-message', { detail: { type: 'message', conversation_id: id } }));
    }, conversationId);
    await new Promise((r) => setTimeout(r, 250));

    assert(requestTimes.length > 0, 'expected the push event to trigger a message-poll request');
    const latency = requestTimes[0] - dispatchTime;
    assert(latency < 250, `expected the triggered poll to fire almost immediately after the push event (well under the 1000ms interval), got ${latency}ms`);

    noErrors(alice, 'push-triggered refresh');
    await closeSession(alice);
});
