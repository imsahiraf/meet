import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, absoluteUrl } from './support/session.js';
import { ensureConversationUrl } from './support/fixtures.js';

// Screen share lives in the More menu (the control bar keeps More, Camera, Speaker, Mute, End).
async function pressShare(page, how = 'click') {
    await page[how]('#call-more-btn');
    await page.waitForFunction(() => !document.getElementById('call-more-menu').classList.contains('hidden'), { timeout: 4000 });
    await page[how]('#call-share-btn');
}

/**
 * Regression test for the reported "call icons and call UI don't
 * render/work correctly on mobile browser or the installed PWA, even
 * though desktop browser is fine".
 *
 * Two real root causes were found and are covered here:
 *
 *  1. The "More" control's icon was the HTML entity &#8942; (U+22EE
 *     VERTICAL ELLIPSIS) - a text glyph, not an SVG. That codepoint is
 *     absent from the default system font stack on plenty of Android and
 *     iOS builds, so it rendered as nothing or as a tofu box on exactly
 *     the devices that were reported broken while desktop Chrome (which
 *     has fonts covering it) looked correct. Same for the add-member
 *     modal's &#10005; close glyph. Both are real <svg> now.
 *
 *  2. The call panels were `fixed inset-0`, which resolves against the
 *     LARGE viewport - the one that ignores mobile Safari/Chrome's own
 *     toolbar entirely. The bottom control row (More/Share/End) was
 *     therefore laid out underneath the browser toolbar and the iOS
 *     home-indicator area, unreachable, with zero safe-area handling
 *     anywhere in the call UI. They are .call-panel now: 100dvh (the
 *     DYNAMIC viewport, which does shrink for a visible toolbar) plus
 *     env(safe-area-inset-*) padding.
 *
 * Honest limitation: Chrome's device emulation does not simulate a
 * dynamic browser toolbar or real safe-area insets, which is precisely
 * why this class of bug survived the existing mobile-viewport tests. So
 * (2) is verified two ways that emulation CAN check faithfully - the
 * panel resolving to the dynamic viewport height rather than a fixed
 * 100vh, and no control ever overflowing at three real phone sizes
 * including a short one - plus asserting the safe-area padding is
 * actually declared on the element.
 */

const PHONES = [
    { name: 'iPhone 14 Pro', width: 393, height: 852 },
    { name: 'iPhone SE', width: 375, height: 667 },
    { name: 'small Android', width: 360, height: 640 },
];

// The control bar of a voice call (camera is added on video calls, share is in the More menu).
const CONTROL_IDS = ['call-more-btn', 'call-speaker-btn', 'call-mute-btn', 'call-end-btn'];

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

// A call is over when the call screen is gone or shows "Call ended" (it stays a few seconds after a connected call).
function callOver(page) {
    // like a user: close the "Call ended" screen, then the page under it is usable again
    return page.evaluate(() => {
        if (!document.getElementById('call-ended').classList.contains('hidden')) document.getElementById('call-ended-close').click();
        return document.getElementById('call-overlay').classList.contains('hidden');
    });
}

function isHidden(page, selector) {
    return page.evaluate((sel) => document.getElementById(sel).classList.contains('hidden'), selector);
}

async function openPage(page, url) {
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => !!window.MeetCalls && !!window.axios, { timeout: 20000 });
}

function measureControls(page, controlIds) {
    return page.evaluate((ids) => {
        const panel = document.getElementById('call-active');
        const panelStyle = getComputedStyle(panel);
        const panelRect = panel.getBoundingClientRect();

        return {
            viewport: { width: innerWidth, height: innerHeight },
            panel: {
                height: Math.round(panelRect.height),
                position: panelStyle.position,
                // The literal declared value, so a regression back to a
                // static 100vh (or inset-0) is caught rather than passing
                // because emulation happens to make them equal.
                declaredHeight: [...document.styleSheets]
                    .flatMap((sheet) => { try { return [...sheet.cssRules]; } catch (e) { return []; } })
                    .filter((rule) => rule.selectorText === '.call-panel')
                    .map((rule) => rule.style.height)[0] || null,
                paddingBottom: panelStyle.paddingBottom,
                declaredPaddingBottom: [...document.styleSheets]
                    .flatMap((sheet) => { try { return [...sheet.cssRules]; } catch (e) { return []; } })
                    .filter((rule) => rule.selectorText === '.call-panel')
                    .map((rule) => rule.style.getPropertyValue('padding-bottom'))[0] || null,
            },
            controls: ids.map((id) => {
                const el = document.getElementById(id);
                if (!el) return { id, missing: true };
                const rect = el.getBoundingClientRect();
                const cx = Math.round(rect.x + rect.width / 2);
                const cy = Math.round(rect.y + rect.height / 2);
                const hit = document.elementFromPoint(cx, cy);
                const svg = el.querySelector('svg');
                return {
                    id,
                    hidden: getComputedStyle(el).display === 'none',
                    rect: { x: Math.round(rect.x), y: Math.round(rect.y), w: Math.round(rect.width), h: Math.round(rect.height) },
                    withinViewport: rect.top >= 0 && rect.left >= 0 && rect.right <= innerWidth && Math.round(rect.bottom) <= innerHeight,
                    // A control the user can genuinely press: the topmost
                    // element at its own centre must be the button itself
                    // or something inside it, not an overlay on top.
                    tappable: !!hit && (hit === el || el.contains(hit)),
                    // Every icon must be a real SVG with real painted
                    // dimensions - a missing font glyph measures as a box
                    // with no SVG at all.
                    hasSvgIcon: !!svg,
                    svgSize: svg ? { w: Math.round(svg.getBoundingClientRect().width), h: Math.round(svg.getBoundingClientRect().height) } : null,
                };
            }),
            bodyScrollsHorizontally: document.documentElement.scrollWidth > document.documentElement.clientWidth,
        };
    }, controlIds);
}

test('mobile: every call control renders as a real SVG icon and stays inside the dynamic viewport at real phone sizes, in a browser tab and as an installed PWA', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);

    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    await alice.page.setViewport({ ...PHONES[0], isMobile: true, hasTouch: true, deviceScaleFactor: 3 });
    await bob.page.setViewport({ ...PHONES[0], isMobile: true, hasTouch: true, deviceScaleFactor: 3 });

    await openPage(bob.page, absoluteUrl('/dashboard'));
    await openPage(alice.page, conversationUrl);

    await alice.page.click('#voice-call-button');
    await waitUntil(async () => !(await isHidden(bob.page, 'call-incoming')), { timeout: 10000, message: 'bob sees the incoming call on a phone viewport' });

    // The ringing screen's Accept/Decline must themselves be on-screen and
    // pressable - they sit at the very bottom of the panel, the same place
    // the old fixed inset-0 layout put things underneath the browser
    // toolbar.
    const ringing = await bob.page.evaluate(() => {
        return ['call-accept-btn', 'call-reject-btn'].map((id) => {
            const el = document.getElementById(id);
            const rect = el.getBoundingClientRect();
            const hit = document.elementFromPoint(Math.round(rect.x + rect.width / 2), Math.round(rect.y + rect.height / 2));
            return {
                id,
                withinViewport: rect.top >= 0 && Math.round(rect.bottom) <= innerHeight,
                tappable: !!hit && (hit === el || el.contains(hit)),
                hasSvgIcon: !!el.querySelector('svg'),
            };
        });
    });
    ringing.forEach((btn) => {
        assert(btn.withinViewport, `${btn.id} must be fully on-screen on a phone, got outside the viewport`);
        assert(btn.tappable, `${btn.id} must be pressable, something is covering it`);
        assert(btn.hasSvgIcon, `${btn.id} must use a real SVG icon`);
    });

    // A genuine touch tap (not a mouse click) - the controls used to rely
    // on a :hover-only affordance, which a touchscreen can never produce.
    await bob.page.tap('#call-accept-btn');

    await waitUntil(async () => !(await isHidden(alice.page, 'call-active')), { timeout: 10000, message: 'alice reaches the active call on a phone viewport' });
    await waitUntil(async () => !(await isHidden(bob.page, 'call-active')), { timeout: 10000, message: 'bob reaches the active call on a phone viewport' });

    for (const phone of PHONES) {
        await alice.page.setViewport({ ...phone, isMobile: true, hasTouch: true, deviceScaleFactor: 3 });
        // Let the resize settle before measuring.
        await new Promise((resolve) => setTimeout(resolve, 400));

        const measured = await measureControls(alice.page, CONTROL_IDS);

        assert(measured.panel.declaredHeight === '100dvh',
            `.call-panel must be sized to the DYNAMIC viewport (100dvh) so a mobile browser toolbar can't cover the controls, got "${measured.panel.declaredHeight}"`);
        assert((measured.panel.declaredPaddingBottom || '').includes('safe-area-inset-bottom'),
            `.call-panel must reserve env(safe-area-inset-bottom) for the home indicator, got "${measured.panel.declaredPaddingBottom}"`);
        assert(measured.panel.height === measured.viewport.height,
            `at ${phone.name} the call panel (${measured.panel.height}px) should fill the viewport (${measured.viewport.height}px)`);
        assert(!measured.bodyScrollsHorizontally, `at ${phone.name} the call UI must not overflow horizontally`);

        measured.controls.forEach((control) => {
            assert(!control.missing, `${control.id} is missing from the DOM`);
            if (control.hidden) {
                return; // e.g. the camera toggle is hidden on a voice call.
            }
            assert(control.withinViewport,
                `at ${phone.name}, ${control.id} is outside the viewport (${JSON.stringify(control.rect)} vs height ${measured.viewport.height}) - this is exactly the bug where the bottom control row sat under the mobile browser toolbar`);
            assert(control.tappable, `at ${phone.name}, ${control.id} is covered by something and cannot be tapped`);
            assert(control.hasSvgIcon,
                `at ${phone.name}, ${control.id} has no SVG icon - a text-glyph icon (the old &#8942; "More" character) renders as a blank or tofu box in many mobile system fonts`);
            assert(control.svgSize.w > 0 && control.svgSize.h > 0,
                `at ${phone.name}, ${control.id}'s icon painted at zero size (${JSON.stringify(control.svgSize)})`);
        });

        // A two-word label ("Video off") wrapping onto a second line
        // inside its narrow grid column made the control row uneven -
        // whitespace:nowrap must hold at every real phone width tested.
        const labelWrap = await alice.page.evaluate(() => {
            return [...document.querySelectorAll('.call-control-label')].map((el) => ({
                text: el.textContent,
                whiteSpace: getComputedStyle(el).whiteSpace,
                wrapped: el.getBoundingClientRect().height > parseFloat(getComputedStyle(el).lineHeight || '0') * 1.5,
            }));
        });
        labelWrap.forEach((label) => {
            assert(label.whiteSpace === 'nowrap', `at ${phone.name}, call-control-label "${label.text}" must have white-space: nowrap, got "${label.whiteSpace}"`);
            assert(!label.wrapped, `at ${phone.name}, call-control-label "${label.text}" wrapped onto a second line`);
        });

        // Real touch interaction, per viewport: the mute control must
        // actually toggle from a tap with no mouse involved.
        const labelBefore = await alice.page.$eval('#call-mute-btn .call-control-label', (el) => el.textContent);
        await alice.page.tap('#call-mute-btn');
        await waitUntil(async () => {
            const now = await alice.page.$eval('#call-mute-btn .call-control-label', (el) => el.textContent);
            return now !== labelBefore;
        }, { timeout: 4000, message: `mute control responds to a real touch tap at ${phone.name}` });
        await alice.page.tap('#call-mute-btn');
    }

    // The "More" overflow menu (and the add-member picker behind it) has
    // to be reachable by touch too - it's the only route to "Add member",
    // the entire entry point for turning a 1:1 call into a group call.
    await alice.page.setViewport({ ...PHONES[2], isMobile: true, hasTouch: true, deviceScaleFactor: 3 });
    await new Promise((resolve) => setTimeout(resolve, 300));
    await alice.page.tap('#call-more-btn');
    await waitUntil(async () => !(await isHidden(alice.page, 'call-more-menu')), { timeout: 4000, message: 'the More menu opens from a touch tap on the smallest phone' });

    const moreMenu = await alice.page.evaluate(() => {
        const menu = document.getElementById('call-more-menu');
        const rect = menu.getBoundingClientRect();
        const addBtn = document.getElementById('call-more-add-member-btn');
        const addRect = addBtn.getBoundingClientRect();
        const hit = document.elementFromPoint(Math.round(addRect.x + addRect.width / 2), Math.round(addRect.y + addRect.height / 2));
        return {
            withinViewport: rect.top >= 0 && Math.round(rect.bottom) <= innerHeight,
            addMemberTappable: !!hit && (hit === addBtn || addBtn.contains(hit)),
            // A 44px-ish minimum touch target, not a desktop-sized row.
            addMemberHeight: Math.round(addRect.height),
        };
    });
    assert(moreMenu.withinViewport, 'the More menu must be fully on-screen on a small phone');
    assert(moreMenu.addMemberTappable, '"Add member" must be tappable - it is the only way to turn a 1:1 call into a group call');
    assert(moreMenu.addMemberHeight >= 40, `"Add member" is only ${moreMenu.addMemberHeight}px tall, too small for a reliable touch target`);

    await alice.page.tap('#call-more-add-member-btn');
    await waitUntil(async () => !(await isHidden(alice.page, 'call-add-member-modal')), { timeout: 5000, message: 'the add-member picker opens by touch' });
    const closeIsSvg = await alice.page.$eval('#call-add-member-close', (el) => !!el.querySelector('svg'));
    assert(closeIsSvg, 'the add-member close button must be an SVG, not the &#10005; text glyph that mobile system fonts can miss');
    await alice.page.tap('#call-add-member-close');

    // Screen sharing on a phone: the honest finding is that no mobile
    // browser implements getDisplayMedia at all, so this asserts the
    // MESSAGE is accurate and specific rather than the old catch-all
    // "not supported in this browser" (reported from a phone as the app
    // claiming the device wasn't supported). Removing getDisplayMedia
    // reproduces a real mobile browser's capabilities.
    await alice.page.evaluate(() => {
        const proto = Object.getPrototypeOf(navigator.mediaDevices);
        delete proto.getDisplayMedia;
        delete navigator.mediaDevices.getDisplayMedia;
    });
    await pressShare(alice.page, 'tap');
    await waitUntil(async () => !(await isHidden(alice.page, 'call-error')), { timeout: 4000, message: 'a screen-share attempt with no getDisplayMedia support explains itself' });
    const shareMessage = await alice.page.$eval('#call-error', (el) => el.textContent);
    assert(/mobile browser/i.test(shareMessage) && /desktop/i.test(shareMessage),
        `the screen-share message must explain that no mobile browser supports it and point at the desktop, got "${shareMessage}"`);

    await alice.page.tap('#call-end-btn');
    await waitUntil(() => callOver(alice.page), { timeout: 8000, message: 'alice overlay clears after ending the call' });

    // 429s here are the shared layout's own contacts/unread badge polling
    // being rate-limited by several simultaneous sessions, unrelated to
    // calls; a late 409/403 is a poll that was already in flight when the
    // call state changed server-side.
    const benign = /429|409 \(Conflict\)|403 \(Forbidden\)/;
    for (const [label, session] of [['alice', alice], ['bob', bob]]) {
        const real = session.errors.filter((e) => !benign.test(e));
        assert(real.length === 0, `${label} unexpected JS errors: ${JSON.stringify(real)}`);
    }

    await closeSession(alice);
    await closeSession(bob);
});

/**
 * The installed-PWA half of the same report. Chrome's DevTools protocol
 * cannot emulate `display-mode: standalone` (Emulation.setEmulatedMedia
 * silently ignores that feature and Puppeteer's own
 * emulateMediaFeatures() rejects it outright), so this launches a real
 * Chrome app-mode window instead - `--app=` produces a genuine
 * standalone display-mode context, confirmed by matchMedia below rather
 * than assumed.
 *
 * A standalone window has no browser toolbar but still has the OS status
 * bar and home indicator, so what matters here is the same thing that
 * was broken in a mobile tab: the panel filling the real viewport and
 * every control being on-screen and hit-testable. The call panel is
 * revealed directly rather than by placing a live call - a second
 * browser and a full WebRTC negotiation would test the signaling layer
 * over again, whereas the PWA-sensitive part is purely the layout.
 */
test('installed PWA (real standalone window): the call screen fills the viewport and every control is on-screen and tappable at a phone size', async () => {
    const puppeteer = (await import('puppeteer-core')).default;
    const { BASE_URL, PASSWORD, CHROME_PATH } = await import('./support/session.js');

    const browser = await puppeteer.launch({
        executablePath: CHROME_PATH,
        headless: 'new',
        args: [
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--use-fake-device-for-media-stream',
            '--use-fake-ui-for-media-stream',
            `--app=${BASE_URL}/login`,
        ],
    });

    try {
        const [page] = await browser.pages();
        const errors = [];
        page.on('pageerror', (err) => errors.push(`PAGEERROR: ${err.message}`));

        const standalone = await page.evaluate(() => matchMedia('(display-mode: standalone)').matches);
        assert(standalone, 'expected a genuine standalone (installed-PWA) display mode from Chrome app mode');

        await page.setViewport({ width: 393, height: 852, isMobile: true, hasTouch: true, deviceScaleFactor: 3 });
        await page.waitForSelector('input[name="login"]');
        await page.type('input[name="login"]', USERS.alice);
        await page.type('input[name="password"]', PASSWORD);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
            page.click('button[type="submit"]'),
        ]);
        await page.waitForFunction(() => !!window.MeetCalls, { timeout: 20000 });

        assert(await page.evaluate(() => matchMedia('(display-mode: standalone)').matches),
            'the app window should still report standalone display mode after logging in');

        // Reveal the connected-call screen with a couple of participant
        // tiles, exactly as the live grid renders them.
        await page.evaluate(() => {
            document.getElementById('call-overlay').classList.remove('hidden');
            document.getElementById('call-overlay').classList.add('flex');
            document.getElementById('call-active').classList.remove('hidden');
            const grid = document.getElementById('call-grid');
            ['self', 'peer'].forEach((who, index) => {
                const tile = document.createElement('div');
                tile.className = 'call-tile';
                tile.dataset.tileRemote = index === 0 ? '0' : '1';
                tile.innerHTML = '<video class="call-tile-video hidden"></video>'
                    + '<div class="call-tile-avatar"><span>A</span></div>'
                    + '<span class="call-tile-name">' + who + '</span>';
                grid.appendChild(tile);
            });
            grid.classList.remove('tiles-1', 'grid-cols-1');
            grid.classList.add('tiles-2', 'grid-cols-1', 'grid-rows-2');
        });
        await new Promise((resolve) => setTimeout(resolve, 300));

        const measured = await measureControls(page, CONTROL_IDS);

        assert(measured.panel.height === measured.viewport.height,
            `in a standalone PWA window the call panel (${measured.panel.height}px) must fill the viewport (${measured.viewport.height}px)`);
        assert(!measured.bodyScrollsHorizontally, 'the call UI must not overflow horizontally in a standalone window');

        measured.controls.filter((control) => !control.hidden).forEach((control) => {
            assert(control.withinViewport,
                `as an installed PWA, ${control.id} is outside the viewport (${JSON.stringify(control.rect)} vs height ${measured.viewport.height})`);
            assert(control.tappable, `as an installed PWA, ${control.id} is covered and cannot be tapped`);
            assert(control.hasSvgIcon, `as an installed PWA, ${control.id} has no SVG icon`);
            assert(control.svgSize.w > 0 && control.svgSize.h > 0, `as an installed PWA, ${control.id}'s icon painted at zero size`);
        });

        // Both tiles must actually be laid out with real area - a grid
        // that collapses to zero height is the other way "the call UI
        // doesn't render" can look.
        const tiles = await page.evaluate(() => {
            return [...document.querySelectorAll('#call-grid .call-tile')].map((tile) => {
                const rect = tile.getBoundingClientRect();
                return { w: Math.round(rect.width), h: Math.round(rect.height), bottom: Math.round(rect.bottom) };
            });
        });
        assert(tiles.length === 2, `expected 2 tiles, got ${tiles.length}`);
        tiles.forEach((tile, index) => {
            assert(tile.w > 100 && tile.h > 100, `tile ${index} collapsed to ${tile.w}x${tile.h} in a standalone window`);
        });

        // And the grid must not push the control row off the bottom.
        const controlsTop = Math.min(...measured.controls.filter((c) => !c.hidden).map((c) => c.rect.y));
        assert(Math.max(...tiles.map((t) => t.bottom)) <= controlsTop + 1,
            'the participant grid overlaps the control row - the grid must shrink to fit, not push controls off-screen');

        assert(errors.length === 0, `unexpected JS errors in the standalone window: ${JSON.stringify(errors)}`);
    } finally {
        await browser.close();
    }
});
