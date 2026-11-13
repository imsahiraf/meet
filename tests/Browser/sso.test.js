import { test, assert } from './support/test.js';
import { getBrowser, absoluteUrl } from './support/session.js';

/**
 * A real end-to-end Google OAuth ceremony needs a real Google account
 * and real client credentials this environment doesn't have - the
 * actual account-creation/linking/2FA-interaction logic is covered
 * thoroughly in SsoAuthenticationTest.php via Socialite::fake(). This
 * test verifies the piece only a real browser can: that the login
 * page's button is wired to the real route, which redirects to a
 * genuine Google OAuth authorize URL built from the configured client
 * ID - without completing the ceremony (there's no real account to
 * consent with here).
 */
test('the login page offers "Continue with Google", which redirects to a real Google OAuth authorize URL', async () => {
    const browser = await getBrowser();
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', (err) => errors.push(`PAGEERROR: ${err.message}`));

    try {
        await page.goto(absoluteUrl('/login'), { waitUntil: 'networkidle0' });

        const googleLink = await page.evaluate(() => {
            const link = [...document.querySelectorAll('a')].find((a) => a.textContent.includes('Continue with Google'));
            return link ? link.href : null;
        });
        assert(googleLink, 'expected a "Continue with Google" link on the login page');
        assert(googleLink.endsWith('/auth/google'), `expected the link to point at /auth/google, got ${googleLink}`);

        // A real top-level navigation (not fetch() - reading a
        // cross-origin fetch response is blocked by CORS even just to
        // see the final URL) so Chrome follows our server's redirect
        // to Google exactly like a real click would. Google's own page
        // doesn't need to finish loading - just confirming the browser
        // actually lands on a real Google OAuth authorize URL built
        // from our configured client_id.
        await page.goto(googleLink, { waitUntil: 'domcontentloaded', timeout: 15000 }).catch(() => {});
        const finalUrl = page.url();

        assert(finalUrl.includes('accounts.google.com'), `expected the redirect to land on accounts.google.com, got ${finalUrl}`);
        assert(finalUrl.includes('client_id='), 'expected a client_id param to be present in the authorize URL (server has GOOGLE_CLIENT_ID configured)');

        assert(errors.length === 0, `unexpected JS errors: ${JSON.stringify(errors)}`);
    } finally {
        await page.close().catch(() => {});
    }
});
