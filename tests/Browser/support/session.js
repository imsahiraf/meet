import puppeteer from 'puppeteer-core';

export const BASE_URL = process.env.BROWSER_TEST_BASE_URL || 'http://localhost:8000';
export const PASSWORD = 'BrowserTest1!';
export const CHROME_PATH = process.env.CHROME_PATH || '/usr/bin/google-chrome';

export const USERS = {
    alice: 'browser.alice@example.test',
    bob: 'browser.bob@example.test',
    carol: 'browser.carol@example.test',
    admin: 'browser.admin@example.test',
};

let sharedBrowser = null;
const openContexts = [];

export async function getBrowser() {
    if (!sharedBrowser) {
        sharedBrowser = await puppeteer.launch({
            executablePath: CHROME_PATH,
            headless: 'new',
            args: [
                '--no-sandbox',
                '--disable-dev-shm-usage',
                // Lets tests exercise the voice-note recorder
                // (getUserMedia/MediaRecorder) without real hardware -
                // Chrome synthesizes a silent audio track instead of
                // prompting for mic permission.
                '--use-fake-device-for-media-stream',
                '--use-fake-ui-for-media-stream',
            ],
        });
    }

    return sharedBrowser;
}

export async function closeBrowser() {
    if (sharedBrowser) {
        await sharedBrowser.close();
        sharedBrowser = null;
    }
}

/**
 * Opens an isolated (own cookie jar) session logged in as the given
 * user email. Returns { page, errors } - errors accumulates any JS
 * console/page error seen for the lifetime of this page, which is the
 * cheapest way to catch "axios is not defined"-class regressions
 * automatically in every test.
 */
export async function loginAs(email, password = PASSWORD) {
    const browser = await getBrowser();
    const context = await browser.createBrowserContext();
    openContexts.push(context);
    const page = await context.newPage();

    const errors = [];
    page.on('pageerror', (err) => errors.push(`PAGEERROR: ${err.message}`));
    page.on('console', (msg) => {
        if (msg.type() === 'error') errors.push(`CONSOLE.ERROR: ${msg.text()}`);
    });
    page.on('requestfailed', (req) => {
        errors.push(`REQUESTFAILED: ${req.url()} - ${req.failure()?.errorText}`);
    });
    page.on('response', (res) => {
        if (res.status() === 429) {
            errors.push(`429 RATE LIMITED: ${res.request().method()} ${res.url()}`);
        }
    });

    await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle0' });
    await page.type('input[name="login"]', email);
    await page.type('input[name="password"]', password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('button[type="submit"]'),
    ]);

    return { page, errors, context };
}

export async function closeSession(session) {
    const idx = openContexts.indexOf(session.context);
    if (idx !== -1) openContexts.splice(idx, 1);
    await session.context.close().catch(() => {});
}

/**
 * Safety net called after every test (pass or fail) so a thrown
 * assertion mid-test can't leak an open browser context into the next
 * test - which previously caused unrelated later tests to fail with
 * confusing, unrelated-looking errors.
 */
export async function closeAllSessions() {
    while (openContexts.length) {
        const context = openContexts.pop();
        await context.close().catch(() => {});
    }
}

/**
 * route()-generated hrefs in this app are absolute (APP_URL-based), so
 * they must be navigated to directly - prefixing with BASE_URL again
 * produces an invalid concatenated URL.
 */
export function absoluteUrl(href) {
    return href.startsWith('http') ? href : `${BASE_URL}${href}`;
}
