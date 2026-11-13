import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, absoluteUrl } from './support/session.js';

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

test('admin dashboard loads live platform stats and a non-admin cannot see the Admin nav link', async () => {
    const admin = await loginAs(USERS.admin);
    await admin.page.goto(absoluteUrl('/admin'), { waitUntil: 'networkidle0' });

    await waitUntil(async () => {
        const text = await admin.page.$eval('#dashboard-stats', (el) => el.textContent).catch(() => '');
        return text.includes('Total Users');
    }, { message: 'dashboard stat cards render' });

    const alice = await loginAs(USERS.alice);
    await alice.page.goto(absoluteUrl('/dashboard'), { waitUntil: 'networkidle0' });
    const hasAdminLink = await alice.page.evaluate(() => !![...document.querySelectorAll('a')].find((a) => a.textContent.trim() === 'Admin'));
    assert(!hasAdminLink, 'a non-admin user should not see the Admin nav link');

    const forbidden = await alice.page.goto(absoluteUrl('/admin'));
    assert(forbidden.status() === 403, `expected a non-admin visiting /admin to get 403, got ${forbidden.status()}`);

    assert(admin.errors.length === 0, `admin unexpected JS errors: ${JSON.stringify(admin.errors)}`);

    await closeSession(admin);
    await closeSession(alice);
});

test('admin can search, suspend, and reactivate a user from the Users page', async () => {
    const admin = await loginAs(USERS.admin);
    await admin.page.goto(absoluteUrl('/admin/users'), { waitUntil: 'networkidle0' });

    await admin.page.type('#user-search', 'Browser Bob');
    await waitUntil(async () => {
        const rows = await admin.page.$$eval('#users-table-body tr', (rows) => rows.length);
        return rows === 1;
    }, { message: 'search narrows the user list to Browser Bob' });

    await admin.page.evaluate(() => {
        [...document.querySelectorAll('[data-action="suspend"]')][0]?.click();
    });

    await waitUntil(async () => {
        const text = await admin.page.$eval('#users-table-body', (el) => el.textContent);
        return text.includes('suspended');
    }, { message: 'Bob shows as suspended after the action' });

    await admin.page.evaluate(() => {
        [...document.querySelectorAll('[data-action="activate"]')][0]?.click();
    });

    await waitUntil(async () => {
        const text = await admin.page.$eval('#users-table-body', (el) => el.textContent);
        return text.includes('active') && !text.includes('suspended');
    }, { message: 'Bob shows as active again' });

    assert(admin.errors.length === 0, `admin unexpected JS errors: ${JSON.stringify(admin.errors)}`);

    await closeSession(admin);
});

test('admin Users page has real vs load-test account tabs, and switching tabs re-filters the list', async () => {
    const admin = await loginAs(USERS.admin);
    await admin.page.goto(absoluteUrl('/admin/users'), { waitUntil: 'networkidle0' });

    const tabLabels = await admin.page.$$eval('.account-type-tab', (tabs) => tabs.map((t) => t.textContent.trim()));
    assert(JSON.stringify(tabLabels) === JSON.stringify(['All users', 'Real users', 'Load-test users']), `expected the 3 account-type tabs, got: ${JSON.stringify(tabLabels)}`);

    await admin.page.type('#user-search', 'Browser Bob');
    await waitUntil(async () => (await admin.page.$$eval('#users-table-body tr', (rows) => rows.length)) === 1, { message: 'search narrows to Browser Bob on the default "All users" tab' });

    await admin.page.click('[data-account-type="fake"]');
    await waitUntil(async () => (await admin.page.$$eval('#users-table-body tr', (rows) => rows.length)) === 0, { message: 'Browser Bob (a real account) disappears once filtered to "Load-test users"' });

    await admin.page.click('[data-account-type="real"]');
    await waitUntil(async () => (await admin.page.$$eval('#users-table-body tr', (rows) => rows.length)) === 1, { message: 'Browser Bob reappears under "Real users"' });

    assert(admin.errors.length === 0, `admin unexpected JS errors: ${JSON.stringify(admin.errors)}`);

    await closeSession(admin);
});

test('admin can create a role, grant it a permission, and delete it', async () => {
    const admin = await loginAs(USERS.admin);
    await admin.page.goto(absoluteUrl('/admin/roles'), { waitUntil: 'networkidle0' });

    await admin.page.type('#new-role-name', 'Support Agent');
    await admin.page.evaluate(() => {
        var checkbox = [...document.querySelectorAll('#new-role-permissions input')].find((el) => el.value === 'view_analytics');
        if (checkbox) checkbox.checked = true;
    });
    await admin.page.click('#create-role-form button[type="submit"]');

    await waitUntil(async () => {
        const text = await admin.page.$eval('#roles-list', (el) => el.textContent).catch(() => '');
        return text.includes('Support Agent');
    }, { message: 'the new role appears in the roles list' });

    // The delete confirmation is now the app's own themed MeetDialog
    // (an in-page modal), not a native confirm() - Puppeteer's
    // 'dialog' event never fires for it, so click its own OK button.
    await admin.page.evaluate(() => {
        var card = [...document.querySelectorAll('#roles-list > div')].find((el) => el.textContent.includes('Support Agent'));
        var deleteBtn = card?.querySelector('button');
        deleteBtn?.click();
    });
    await admin.page.waitForSelector('#meet-dialog-overlay.flex');
    await admin.page.click('#meet-dialog-ok');

    await waitUntil(async () => {
        const text = await admin.page.$eval('#roles-list', (el) => el.textContent).catch(() => '');
        return !text.includes('Support Agent');
    }, { message: 'the role is removed after deletion' });

    assert(admin.errors.length === 0, `admin unexpected JS errors: ${JSON.stringify(admin.errors)}`);

    await closeSession(admin);
});

test('admin can update branding and toggle a feature flag, and admin actions show up in the audit log', async () => {
    const admin = await loginAs(USERS.admin);
    await admin.page.goto(absoluteUrl('/admin/settings'), { waitUntil: 'networkidle0' });

    await admin.page.click('[data-tab="branding"]');
    await waitUntil(async () => !(await admin.page.$eval('#panel-branding', (el) => el.classList.contains('hidden'))), { message: 'branding tab opens' });

    await admin.page.evaluate(() => { document.getElementById('branding-app-name').value = ''; });
    await admin.page.type('#branding-app-name', 'Meet Browser Test');
    // "Branding saved." now shows via the app's own themed MeetDialog
    // (an in-page modal), not a native alert() - dismiss it via its
    // own OK button rather than Puppeteer's 'dialog' event, which
    // never fires for it.
    await admin.page.click('#save-branding');
    await admin.page.waitForSelector('#meet-dialog-overlay.flex');
    await admin.page.click('#meet-dialog-ok');
    await new Promise((r) => setTimeout(r, 500));

    await admin.page.click('[data-tab="features"]');
    await waitUntil(async () => {
        const text = await admin.page.$eval('#panel-features', (el) => el.textContent).catch(() => '');
        return text.length > 0;
    }, { message: 'feature flags load' });

    await admin.page.evaluate(() => {
        var checkbox = document.querySelector('#panel-features input[type="checkbox"]');
        checkbox?.click();
    });
    await new Promise((r) => setTimeout(r, 500));

    await admin.page.goto(absoluteUrl('/admin/logs'), { waitUntil: 'networkidle0' });
    await waitUntil(async () => {
        const text = await admin.page.$eval('#audit-log-body', (el) => el.textContent).catch(() => '');
        return text.includes('branding_updated') || text.includes('feature_flag_changed');
    }, { message: 'the branding/feature-flag change appears in the audit log' });

    assert(admin.errors.length === 0, `admin unexpected JS errors: ${JSON.stringify(admin.errors)}`);

    await closeSession(admin);
});

test('a super admin can trigger a manual archive and download it once complete', async () => {
    const admin = await loginAs(USERS.admin);
    await admin.page.goto(absoluteUrl('/admin/archives'), { waitUntil: 'networkidle0' });

    await admin.page.click('#trigger-archive');
    await waitUntil(async () => {
        const text = await admin.page.$eval('#archive-action-result', (el) => el.textContent).catch(() => '');
        return text.includes('created');
    }, { message: 'the archive request is accepted' });

    await waitUntil(async () => {
        const text = await admin.page.$eval('#archives-body', (el) => el.textContent).catch(() => '');
        return text.includes('delivered') || text.includes('completed');
    }, { timeout: 20000, message: 'the archive finishes building' });

    const hasDownloadLink = await admin.page.evaluate(() => !!document.querySelector('#archives-body a'));
    assert(hasDownloadLink, 'a completed archive should have a download link');

    assert(admin.errors.length === 0, `admin unexpected JS errors: ${JSON.stringify(admin.errors)}`);

    await closeSession(admin);
});

test('the PWA manifest and service worker are reachable and installable metadata is correct', async () => {
    const admin = await loginAs(USERS.admin);
    await admin.page.goto(absoluteUrl('/dashboard'), { waitUntil: 'networkidle0' });

    const manifestHref = await admin.page.$eval('link[rel="manifest"]', (el) => el.getAttribute('href'));
    assert(manifestHref, 'the page should link to a web app manifest');

    const manifestResponse = await admin.page.goto(absoluteUrl(manifestHref));
    assert(manifestResponse.status() === 200, `expected the manifest to be reachable, got ${manifestResponse.status()}`);
    const manifest = JSON.parse(await manifestResponse.text());
    assert(manifest.icons && manifest.icons.length >= 2, 'manifest should declare at least two icons');
    assert(manifest.name && manifest.short_name, 'manifest should declare a name and short_name');

    await admin.page.goto(absoluteUrl('/dashboard'), { waitUntil: 'networkidle0' });
    await waitUntil(() => admin.page.evaluate(async () => {
        const registrations = await navigator.serviceWorker.getRegistrations();
        return registrations.length > 0;
    }), { timeout: 10000, message: 'the service worker registers itself' });

    await closeSession(admin);
});

test('the header shows the real Meet logo mark, the app version, and a working install button once beforeinstallprompt fires', async () => {
    const admin = await loginAs(USERS.admin);
    await admin.page.goto(absoluteUrl('/dashboard'), { waitUntil: 'networkidle0' });

    const logoOk = await admin.page.evaluate(() => {
        const img = document.querySelector('header img[alt="Meet"]');
        return !!img && img.naturalWidth > 0 && img.src.includes('/icons/icon-192.png');
    });
    assert(logoOk, 'the header should show the real icon-192.png logo, not a broken image');

    const footerText = await admin.page.$eval('footer', (el) => el.textContent);
    assert(/Meet v\d+\.\d+\.\d+/.test(footerText), `expected the footer to show a semver app version, got: ${footerText}`);

    const installBtnHiddenInitially = await admin.page.evaluate(() => document.getElementById('pwa-install-btn').classList.contains('hidden'));
    assert(installBtnHiddenInitially, 'the install button should stay hidden until a beforeinstallprompt event actually fires');

    // Headless Chrome won't naturally fire beforeinstallprompt (its
    // install-eligibility heuristics need a real user-engagement
    // history), so this simulates the event the same way our own code
    // listens for it, to verify the button wiring itself works.
    await admin.page.evaluate(() => {
        window.__meetInstallPrompt = { prompt: () => {}, userChoice: Promise.resolve({ outcome: 'accepted' }) };
        window.dispatchEvent(new CustomEvent('meet:pwa-installable'));
    });

    await waitUntil(async () => !(await admin.page.evaluate(() => document.getElementById('pwa-install-btn').classList.contains('hidden'))), { message: 'the install button appears once beforeinstallprompt-equivalent fires' });

    await admin.page.click('#pwa-install-btn');
    await waitUntil(async () => await admin.page.evaluate(() => document.getElementById('pwa-install-btn').classList.contains('hidden')), { message: 'the install button hides again after the prompt resolves' });

    assert(admin.errors.length === 0, `admin unexpected JS errors: ${JSON.stringify(admin.errors)}`);

    await closeSession(admin);
});

test('on iOS Safari (no beforeinstallprompt support), the install button shows instructions instead', async () => {
    const alice = await loginAs(USERS.alice);
    await alice.page.setUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1');
    await alice.page.goto(absoluteUrl('/dashboard'), { waitUntil: 'networkidle0' });

    const installBtnVisible = await alice.page.evaluate(() => !document.getElementById('pwa-install-btn').classList.contains('hidden'));
    assert(installBtnVisible, 'expected the install button to show on iOS even though beforeinstallprompt never fires there');

    await alice.page.click('#pwa-install-btn');
    await waitUntil(async () => !(await alice.page.evaluate(() => document.getElementById('ios-install-instructions').classList.contains('hidden'))), { message: 'the iOS install instructions modal opens' });

    const instructionsText = await alice.page.$eval('#ios-install-instructions', (el) => el.textContent);
    assert(instructionsText.includes('Add to Home Screen'), `expected the modal to mention "Add to Home Screen", got: ${instructionsText}`);

    await alice.page.click('#ios-install-close');
    await waitUntil(async () => await alice.page.evaluate(() => document.getElementById('ios-install-instructions').classList.contains('hidden')), { message: 'the modal closes after "Got it"' });

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);

    await closeSession(alice);
});
