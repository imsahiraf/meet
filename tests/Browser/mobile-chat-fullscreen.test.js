import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS } from './support/session.js';
import { ensureConversationUrl } from './support/fixtures.js';

const dir = path.dirname(fileURLToPath(import.meta.url));
const IMAGE_FIXTURE = path.join(dir, 'fixtures/test-image.png');

test('mobile chat: an open conversation is full-screen (no app header/bottom nav) like WhatsApp/Instagram, with a working back button', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    await alice.page.setViewport({ width: 375, height: 812 });

    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    const layout = await alice.page.evaluate(() => {
        const header = document.querySelector('header');
        const bottomNav = document.getElementById('app-bottom-nav');
        const chatHeader = document.getElementById('chat-thread-header');
        return {
            appHeaderVisible: header && header.getBoundingClientRect().height > 0 && getComputedStyle(header).display !== 'none',
            bottomNavVisible: bottomNav && getComputedStyle(bottomNav).display !== 'none',
            chatHeaderVisible: chatHeader && getComputedStyle(chatHeader).display !== 'none',
        };
    });
    assert(!layout.appHeaderVisible, 'expected the app-level header to be hidden on mobile inside an open conversation');
    assert(!layout.bottomNavVisible, 'expected the app-level bottom nav to be hidden on mobile inside an open conversation');
    assert(layout.chatHeaderVisible, "expected the conversation's own header (back arrow, name, call buttons) to still be visible");

    const backHref = await alice.page.$eval('#chat-thread-header a', (el) => el.getAttribute('href'));
    assert(backHref.endsWith('/chat'), `expected the conversation's own back arrow to link back to the chat list, got "${backHref}"`);

    // Desktop is unaffected - the WhatsApp-Web-style split view (with
    // its own header/sidebar) should still show normally.
    await alice.page.setViewport({ width: 1280, height: 800 });
    await new Promise((r) => setTimeout(r, 200));
    const desktopLayout = await alice.page.evaluate(() => {
        const header = document.querySelector('header');
        return header && getComputedStyle(header).display !== 'none';
    });
    assert(desktopLayout, 'expected the app-level header to still show at a desktop viewport');

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);
    await closeSession(alice);
});

test('mobile chat: the composer stays pinned to the bottom even after aggressively scrolling the message list - <main> is never itself a second scrollable ancestor', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    await alice.page.setViewport({ width: 375, height: 667 });
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    // Enough messages that #messages genuinely overflows and scrolls.
    for (let i = 0; i < 15; i++) {
        await alice.page.type('#composer-input', `Filler message ${i} ${Date.now()}`);
        await alice.page.click('#composer button[type="submit"]');
        await new Promise((r) => setTimeout(r, 150));
    }
    await new Promise((r) => setTimeout(r, 500));

    const viewportHeight = await alice.page.evaluate(() => window.innerHeight);
    const composerBottomBefore = await alice.page.evaluate(() => document.getElementById('composer').getBoundingClientRect().bottom);
    assert(Math.abs(composerBottomBefore - viewportHeight) < 2, `expected the composer to sit flush at the bottom of the viewport before scrolling, got bottom=${composerBottomBefore} vs viewport=${viewportHeight}`);

    // <main> itself must never be scrollable on this page - if it were,
    // this direct scrollTop write would actually move it.
    const appMainOverflow = await alice.page.evaluate(() => getComputedStyle(document.getElementById('app-main')).overflowY);
    assert(appMainOverflow === 'hidden', `expected <main> to be overflow-hidden on this full-bleed mobile page, got overflow-y: ${appMainOverflow}`);

    // Chromium's touch-scroll emulation doesn't reproduce iOS Safari's
    // rubber-band bounce physics, so the CDP gesture below can't catch
    // a real regression here on its own - assert the CSS itself is
    // actually in place (the real fix for the "footer briefly comes
    // unstuck" report, which only ever showed up on a real iPhone).
    const overscrollValues = await alice.page.evaluate(() => ({
        html: getComputedStyle(document.documentElement).overscrollBehaviorY,
        body: getComputedStyle(document.body).overscrollBehaviorY,
        messages: getComputedStyle(document.getElementById('messages')).overscrollBehaviorY,
    }));
    assert(overscrollValues.html === 'none', `expected <html> to have overscroll-behavior-y: none, got "${overscrollValues.html}"`);
    assert(overscrollValues.body === 'none', `expected <body> to have overscroll-behavior-y: none, got "${overscrollValues.body}"`);
    assert(overscrollValues.messages === 'contain', `expected #messages to have overscroll-behavior-y: contain, got "${overscrollValues.messages}"`);
    await alice.page.evaluate(() => { document.getElementById('app-main').scrollTop = 200; });
    const appMainScrollTopAfterDirectSet = await alice.page.evaluate(() => document.getElementById('app-main').scrollTop);
    assert(appMainScrollTopAfterDirectSet === 0, `expected <main> to refuse to scroll at all (overflow-hidden), got scrollTop=${appMainScrollTopAfterDirectSet}`);

    // A real touch scroll gesture dragged well past the message list's
    // own scroll boundary - the kind of overscroll that would rubber-
    // band/scroll-chain into an outer scrollable ancestor if one existed.
    const messagesBox = await alice.page.evaluate(() => {
        const rect = document.getElementById('messages').getBoundingClientRect();
        return { x: rect.x + rect.width / 2, y: rect.y + rect.height / 2 };
    });
    const client = await alice.page.createCDPSession();
    for (let i = 0; i < 5; i++) {
        await client.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: messagesBox.x, y: messagesBox.y + 200 }] });
        await client.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: messagesBox.x, y: messagesBox.y - 200 }] });
        await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
        await new Promise((r) => setTimeout(r, 100));
    }
    await new Promise((r) => setTimeout(r, 300));

    const composerBottomAfter = await alice.page.evaluate(() => document.getElementById('composer').getBoundingClientRect().bottom);
    assert(Math.abs(composerBottomAfter - viewportHeight) < 2, `expected the composer to STILL sit flush at the bottom of the viewport after scrolling the message list, got bottom=${composerBottomAfter} vs viewport=${viewportHeight} (a gap here is exactly the reported "footer comes up" bug)`);

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);
    await closeSession(alice);
});

test('mobile chat: replying AND attaching together (in the same compose action) does not corrupt the composer layout', async () => {
    // Real bug: startReply() calls composerInput.focus() directly, and
    // opening the native attachment/camera picker blurs it again (then
    // refocuses on return) - each of those fires its own uncoordinated
    // burst of syncHeightSoon() timers (see show.blade.php), which used
    // to stack rather than cancel each other, each reading whatever
    // visualViewport.height happened to be at that exact moment. Doing
    // reply and attach back-to-back (not each alone, which the two
    // tests above already cover individually) is what actually
    // triggers both bursts close together.
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    await alice.page.setViewport({ width: 375, height: 667 });
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    const text = `Reply+attach target ${Date.now()}`;
    await alice.page.type('#composer-input', text);
    await alice.page.click('#composer button[type="submit"]');
    await new Promise((r) => setTimeout(r, 500));

    const viewportHeight = await alice.page.evaluate(() => window.innerHeight);

    // Double-click (the desktop-mouse equivalent of a real long-press)
    // opens the actions card; clicking Reply from there calls the same
    // startReply() the long-press/swipe-to-reply paths do - this isn't
    // what's under test, the height-sync race triggered by it is.
    const bubble = await alice.page.evaluateHandle((t) => {
        const container = [...document.querySelectorAll('[data-message-id]')].find((el) => el.dataset.body === t);
        return container.querySelector('.message-bubble');
    }, text);
    await bubble.click({ clickCount: 2 });
    await alice.page.evaluate((t) => {
        const container = [...document.querySelectorAll('[data-message-id]')].find((el) => el.dataset.body === t);
        container.querySelector('.message-actions-menu [data-action="reply"]').click();
    }, text);

    // No wait here - the attachment picker firing its own blur/refocus
    // burst WHILE the reply's focus burst is still in flight is exactly
    // the race being tested.
    const fileInput = await alice.page.$('#attachment-input-gallery');
    await fileInput.uploadFile(IMAGE_FIXTURE);
    await new Promise((r) => setTimeout(r, 700));

    const state = await alice.page.evaluate(() => ({
        replyBannerVisible: !document.getElementById('reply-banner').classList.contains('hidden'),
        attachmentPreviewVisible: !document.getElementById('attachment-preview').classList.contains('hidden'),
        composerBottom: document.getElementById('composer').getBoundingClientRect().bottom,
        containerHeight: document.getElementById('chat-app-container').getBoundingClientRect().height,
    }));

    assert(state.replyBannerVisible, 'expected the reply banner to still be visible with an attachment also pending');
    assert(state.attachmentPreviewVisible, 'expected the attachment preview banner to actually be visible for this to be a meaningful check');
    assert(Math.abs(state.composerBottom - viewportHeight) < 2, `expected the composer flush at the bottom with BOTH banners showing, got bottom=${state.composerBottom} vs viewport=${viewportHeight} (a gap/shift here is exactly the reported "zoom"/expand glitch)`);
    assert(Math.abs(state.containerHeight - viewportHeight) < 2, `expected the chat container's own pinned height to settle at the real viewport height, got ${state.containerHeight} vs ${viewportHeight}`);

    // The combination should still actually work, not just look right.
    await alice.page.click('#composer button[type="submit"]');
    await new Promise((r) => setTimeout(r, 800));
    const sentOk = await alice.page.evaluate(() => {
        const bubbles = [...document.querySelectorAll('.message-bubble img')];
        return bubbles.length > 0;
    });
    assert(sentOk, 'expected the reply+attachment message to have actually sent with its image');

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);
    await closeSession(alice);
});

test('mobile chat: the composer stays pinned to the bottom when the attachment preview appears above it', async () => {
    // Real bug: #messages was flex-1 without min-height: 0, the classic
    // flexbox gotcha - a flex child otherwise refuses to shrink below
    // its own content's natural height, so a sibling appearing above the
    // composer (the attachment preview banner) pushed the composer out
    // of the fixed-height column instead of #messages simply getting a
    // bit shorter to make room, exactly like the equivalent bug already
    // fixed for #call-grid.
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    await alice.page.setViewport({ width: 375, height: 667 });
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    // Enough messages that #messages has real content height to refuse
    // to shrink from, if the bug were still present.
    for (let i = 0; i < 10; i++) {
        await alice.page.type('#composer-input', `Filler message ${i} ${Date.now()}`);
        await alice.page.click('#composer button[type="submit"]');
        await new Promise((r) => setTimeout(r, 150));
    }
    await new Promise((r) => setTimeout(r, 500));

    const viewportHeight = await alice.page.evaluate(() => window.innerHeight);
    const composerBottomBefore = await alice.page.evaluate(() => document.getElementById('composer').getBoundingClientRect().bottom);
    assert(Math.abs(composerBottomBefore - viewportHeight) < 2, `expected the composer flush at the bottom before attaching, got bottom=${composerBottomBefore} vs viewport=${viewportHeight}`);

    const fileInput = await alice.page.$('#attachment-input-gallery');
    await fileInput.uploadFile(IMAGE_FIXTURE);
    await new Promise((r) => setTimeout(r, 400));

    const previewVisible = await alice.page.evaluate(() => !document.getElementById('attachment-preview').classList.contains('hidden'));
    assert(previewVisible, 'expected the attachment preview banner to actually be visible for this to be a meaningful check');

    const composerBottomAfter = await alice.page.evaluate(() => document.getElementById('composer').getBoundingClientRect().bottom);
    assert(Math.abs(composerBottomAfter - viewportHeight) < 2, `expected the composer to STILL sit flush at the bottom while attaching, got bottom=${composerBottomAfter} vs viewport=${viewportHeight} (a gap/shift here is exactly the reported "footer moves up when attaching" bug)`);

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);
    await closeSession(alice);
});

test('mobile chat: the conversation container tracks visualViewport height, so it never leaves the composer hidden under a shrunk viewport (simulating the on-screen keyboard)', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.carol);
    const alice = await loginAs(USERS.alice);
    await alice.page.setViewport({ width: 375, height: 812 });

    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    const fullHeight = await alice.page.evaluate(() => document.getElementById('chat-app-container').getBoundingClientRect().height);
    assert(Math.abs(fullHeight - 812) < 5, `expected the container to start at the full viewport height (~812px), got ${fullHeight}`);

    // Simulating the visual viewport shrinking the way it does when a
    // real on-screen keyboard opens and covers roughly half the screen.
    await alice.page.setViewport({ width: 375, height: 400 });
    await new Promise((r) => setTimeout(r, 300));

    const shrunkHeight = await alice.page.evaluate(() => document.getElementById('chat-app-container').getBoundingClientRect().height);
    assert(Math.abs(shrunkHeight - 400) < 5, `expected the container to track the shrunk visual viewport (~400px), got ${shrunkHeight}`);

    const composerVisible = await alice.page.evaluate(() => {
        const rect = document.getElementById('composer').getBoundingClientRect();
        return rect.bottom > 0 && rect.bottom <= 400 && rect.top >= 0;
    });
    assert(composerVisible, 'expected the composer to stay fully within the visible (shrunk) viewport, not hidden below it');

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);
    await closeSession(alice);
});

test('mobile chat (installed PWA): the composer still re-syncs on keyboard open/close even if visualViewport\'s own resize event never fires', async () => {
    // Direct feedback: the composer footer still jumps specifically in
    // the installed Android PWA, not in a plain mobile browser tab. This
    // is a real, long-standing Chromium gap - visualViewport's own
    // 'resize' event is unreliable inside a standalone/WebAPK window,
    // sometimes never firing at all for the on-screen keyboard, unlike
    // in a normal tab (which the test above already covers via a real
    // viewport-shrink event). This test simulates exactly that gap: the
    // native event is bypassed entirely (window.visualViewport is
    // replaced with a plain stub, so there is no event to listen for),
    // and only the composer's own focus/blur - the imperative fallback
    // added for this - is what re-syncs the container height.
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    await alice.page.setViewport({ width: 375, height: 812 });

    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    const fullHeight = await alice.page.evaluate(() => document.getElementById('chat-app-container').getBoundingClientRect().height);
    assert(Math.abs(fullHeight - 812) < 5, `expected the container to start at the full viewport height (~812px), got ${fullHeight}`);

    await alice.page.evaluate(() => {
        // Deliberately a plain object with no addEventListener at all -
        // any code path that depends on a visualViewport event firing
        // would silently do nothing from this point on.
        window.visualViewport = { height: 400 };
        document.getElementById('composer-input').dispatchEvent(new FocusEvent('focus'));
    });

    await new Promise((resolve) => setTimeout(resolve, 700));

    const shrunkHeight = await alice.page.evaluate(() => document.getElementById('chat-app-container').getBoundingClientRect().height);
    assert(Math.abs(shrunkHeight - 400) < 5, `expected focusing the composer to re-sync the container to the (stubbed) shrunk height (~400px) on its own, got ${shrunkHeight}`);

    await alice.page.evaluate(() => {
        window.visualViewport = { height: 812 };
        document.getElementById('composer-input').dispatchEvent(new FocusEvent('blur'));
    });
    await new Promise((resolve) => setTimeout(resolve, 700));

    const restoredHeight = await alice.page.evaluate(() => document.getElementById('chat-app-container').getBoundingClientRect().height);
    assert(Math.abs(restoredHeight - 812) < 5, `expected blurring the composer to re-sync the container back to the full height (~812px) on its own, got ${restoredHeight}`);

    await closeSession(alice);
});

test('mobile chat: the composer never computes under 16px, and page zoom is disabled - the two real fixes for "typing causes zoom"', async () => {
    // "Normal typing causes zoom" has two entirely different possible
    // meanings, and both already have a real fix in place (app.css's
    // base-layer text-base rule, and the viewport meta tag) - this
    // locks both in with an actual rendered check, since neither had
    // ever been verified against a real browser engine's computed
    // styles, only reasoned about from the source.
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    await alice.page.setViewport({ width: 375, height: 667 });
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    // iOS Safari force-zooms the whole page in when a focused input's
    // computed font-size is under 16px - the composer textarea itself
    // sets no base-width text-size class at all (only sm:text-sm, which
    // doesn't apply below that breakpoint), so this is entirely
    // resting on app.css's global `textarea { text-base sm:text-sm }`
    // base-layer rule actually still applying to it.
    const fontSize = await alice.page.evaluate(() => parseFloat(getComputedStyle(document.getElementById('composer-input')).fontSize));
    assert(fontSize >= 16, `expected the composer's computed font-size to be at least 16px to avoid iOS Safari's auto-zoom-on-focus, got ${fontSize}px`);

    const viewportMeta = await alice.page.evaluate(() => document.querySelector('meta[name="viewport"]').content);
    assert(viewportMeta.includes('user-scalable=no') && viewportMeta.includes('maximum-scale=1'), `expected the viewport meta to disable pinch/double-tap page zoom, got "${viewportMeta}"`);

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);
    await closeSession(alice);
});
