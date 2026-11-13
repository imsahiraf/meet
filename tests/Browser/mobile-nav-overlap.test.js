import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, absoluteUrl } from './support/session.js';

/**
 * Regression test for the fixed mobile bottom nav bar overlapping
 * actionable page content (see BACKLOG.md "Known rough edges") - two
 * real Puppeteer failures earlier surfaced this via coordinate-based
 * clicks landing on the bar instead of the intended button, on a
 * short/default test viewport rather than a genuine small-phone one.
 *
 * Confirmed via manual repro before writing this: the default
 * scrollIntoView() (block: 'nearest') already stayed clear of the bar
 * given how long this page has grown, so it wasn't actually
 * discriminating a real fix from no fix at all. Forcing block: 'end'
 * (what a "scroll this to the very bottom" implementation would use)
 * reliably reproduced the bug pre-fix - the button landed fully behind
 * the bar - and reliably passes post-fix (scroll-margin-bottom on
 * focusable elements below the `lg` breakpoint). That's the scenario
 * this test exercises.
 */
test('the fixed mobile bottom nav bar never overlaps a scrolled-to button at a real small-phone viewport', async () => {
    const bob = await loginAs(USERS.bob);
    const { page } = bob;

    await page.setViewport({ width: 375, height: 667 });
    // Account & Security became a sub-hub (see profile.security) with
    // this form living on its own dedicated page now - the CSS fix
    // itself (scroll-margin-bottom, app.css) is a global rule, not
    // specific to page length, so this still exercises it faithfully.
    await page.goto(absoluteUrl('/profile/deactivate'), { waitUntil: 'networkidle0' });

    const result = await page.evaluate(() => {
        const form = [...document.querySelectorAll('form')].find((f) => f.action.endsWith('/profile') && f.querySelector('input[name="password"]'));
        const btn = form ? form.querySelector('button[type="submit"]') : null;
        if (!btn) return null;

        btn.scrollIntoView({ block: 'end' });

        const btnRect = btn.getBoundingClientRect();
        const nav = document.querySelector('nav.fixed.bottom-0');
        const navRect = nav ? nav.getBoundingClientRect() : null;

        return {
            btnBottom: btnRect.bottom,
            btnCenterX: btnRect.left + btnRect.width / 2,
            btnCenterY: btnRect.top + btnRect.height / 2,
            navTop: navRect ? navRect.top : null,
        };
    });
    assert(result, 'expected to find the "Deactivate my account" submit button and the fixed nav bar');
    assert(result.navTop !== null, 'expected the fixed mobile bottom nav bar to be present at this viewport');

    assert(
        result.btnBottom <= result.navTop,
        `expected the deactivate button (bottom=${result.btnBottom}) to sit fully above the nav bar (top=${result.navTop}) after a block:'end' scrollIntoView`,
    );

    // A real coordinate-based click at the button's own center must
    // actually land on the button, not the bar underneath/behind it.
    const elementAtPoint = await page.evaluate((x, y) => {
        const el = document.elementFromPoint(x, y);
        return el ? (el.tagName + (el.type ? ':' + el.type : '')) : null;
    }, result.btnCenterX, result.btnCenterY);
    assert(elementAtPoint === 'BUTTON:submit', `expected the deactivate button itself at its own center point, got ${elementAtPoint}`);

    assert(bob.errors.length === 0, `unexpected JS errors: ${JSON.stringify(bob.errors)}`);

    await closeSession(bob);
});
