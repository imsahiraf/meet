import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, absoluteUrl } from './support/session.js';

test('settings hub links to every category and each page saves/prefills its own fields', async () => {
    const carol = await loginAs(USERS.carol);
    const { page } = carol;

    await page.goto(absoluteUrl('/profile'), { waitUntil: 'networkidle0' });
    const hubText = await page.evaluate(() => document.body.textContent);
    ['Profile', 'Preferences', 'Notifications', 'Chat defaults', 'Privacy', 'Account & Security'].forEach((label) => {
        assert(hubText.includes(label), `expected the settings hub to link to "${label}"`);
    });

    // Profile page - display_name/date_of_birth/gender.
    await page.goto(absoluteUrl('/profile/info'), { waitUntil: 'networkidle0' });
    await page.evaluate(() => { document.querySelector('input[name="display_name"]').value = ''; });
    await page.type('input[name="display_name"]', 'Settings Test Display');
    await page.evaluate(() => { document.querySelector('input[name="date_of_birth"]').value = '1990-05-15'; });
    await page.select('select[name="gender"]', 'other');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => document.querySelector('form[action$="/profile"]').submit()),
    ]);

    // Preferences page - timezone + the 3-way auto-download split.
    await page.goto(absoluteUrl('/profile/preferences'), { waitUntil: 'networkidle0' });
    await page.select('select[name="timezone"]', 'Asia/Kolkata');
    await page.evaluate(() => { document.querySelector('input[type="checkbox"][name="auto_download_photos"]').checked = true; });
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => document.querySelector('form[action$="/profile/preferences"]').submit()),
    ]);

    // Notifications page - its own separate route now, posting to the same preferences action.
    await page.goto(absoluteUrl('/profile/notifications'), { waitUntil: 'networkidle0' });
    await page.evaluate(() => {
        document.querySelector('input[type="checkbox"][name="notify_calls"]').checked = false;
    });
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => document.querySelector('form[action$="/profile/preferences"]').submit()),
    ]);

    await page.goto(absoluteUrl('/profile/info'), { waitUntil: 'networkidle0' });
    const infoValues = await page.evaluate(() => ({
        displayName: document.querySelector('input[name="display_name"]').value,
        dob: document.querySelector('input[name="date_of_birth"]').value,
        gender: document.querySelector('select[name="gender"]').value,
    }));
    assert(infoValues.displayName === 'Settings Test Display', `expected display_name to persist, got ${infoValues.displayName}`);
    assert(infoValues.dob === '1990-05-15', `expected date_of_birth to persist, got ${infoValues.dob}`);
    assert(infoValues.gender === 'other', `expected gender to persist, got ${infoValues.gender}`);

    await page.goto(absoluteUrl('/profile/preferences'), { waitUntil: 'networkidle0' });
    const prefValues = await page.evaluate(() => ({
        timezone: document.querySelector('select[name="timezone"]').value,
        autoPhotos: document.querySelector('input[type="checkbox"][name="auto_download_photos"]').checked,
    }));
    assert(prefValues.timezone === 'Asia/Kolkata', `expected timezone to persist, got ${prefValues.timezone}`);
    assert(prefValues.autoPhotos === true, 'expected auto_download_photos to persist as checked');

    await page.goto(absoluteUrl('/profile/notifications'), { waitUntil: 'networkidle0' });
    const notifyCalls = await page.evaluate(() => document.querySelector('input[type="checkbox"][name="notify_calls"]').checked);
    assert(notifyCalls === false, 'expected notify_calls to persist as unchecked');

    assert(carol.errors.length === 0, `carol unexpected JS errors: ${JSON.stringify(carol.errors)}`);

    await closeSession(carol);
});

test('Account & Security is a sub-hub linking directly to its own dedicated page per option, including a prominent Add account entry', async () => {
    const bob = await loginAs(USERS.bob);
    const { page } = bob;

    await page.goto(absoluteUrl('/profile/security'), { waitUntil: 'networkidle0' });

    const hrefsByLabel = await page.evaluate(() => {
        const result = {};
        document.querySelectorAll('a').forEach((a) => {
            const label = a.querySelector('.block.text-sm.font-medium')?.textContent.trim();
            if (label) result[label] = a.getAttribute('href');
        });
        return result;
    });

    const expected = {
        'Change password': '/profile/password',
        'Two-factor authentication': '/profile/two-factor',
        'Passkeys': '/profile/passkeys',
        'Connected accounts': '/profile/connected-accounts',
        'Active devices': '/profile/devices',
        'Switch account': '/profile/switch-accounts',
        'Add account': '/accounts/add',
        'Deactivate account': '/profile/deactivate',
    };
    Object.entries(expected).forEach(([label, path]) => {
        assert(hrefsByLabel[label]?.includes(path), `expected "${label}" to link directly to ${path}, got ${hrefsByLabel[label]}`);
    });

    // "Add account" must be one click from this hub, not nested inside
    // the Switch account page - navigate it directly and confirm it lands
    // on the real add-account form, not just that the href string looks right.
    await page.goto(absoluteUrl(hrefsByLabel['Add account']), { waitUntil: 'networkidle0' });
    const onAddAccountPage = await page.evaluate(() => document.querySelector('input[name="login"]') !== null && document.body.textContent.includes('Add another account'));
    assert(onAddAccountPage, 'expected the Add account link from the hub to land on the real add-account form');

    assert(bob.errors.length === 0, `bob unexpected JS errors: ${JSON.stringify(bob.errors)}`);

    await closeSession(bob);
});

test('change-password form redisplays an error on the wrong current password without crashing', async () => {
    const bob = await loginAs(USERS.bob);
    const { page } = bob;

    await page.goto(absoluteUrl('/profile/password'), { waitUntil: 'networkidle0' });

    await page.type('input[name="current_password"]', 'DefinitelyWrongPass1!');
    await page.type('input[name="new_password"]', 'BrandNewPass1!');
    await page.type('input[name="new_password_confirmation"]', 'BrandNewPass1!');

    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => document.querySelector('form[action$="/profile/password"]').submit()),
    ]);

    const bodyText = await page.evaluate(() => document.body.textContent);
    assert(bodyText.includes('current password is incorrect'), 'expected the current-password error to be shown on the page');

    assert(bob.errors.length === 0, `bob unexpected JS errors: ${JSON.stringify(bob.errors)}`);

    await closeSession(bob);
});

/**
 * Only the failure path is exercised here on purpose - a real success
 * would soft-delete a shared fixture account and break every other
 * browser test file that reuses it in this same run (and future runs,
 * since the seeder only ever firstOrCreate()s these accounts). The full
 * success path (soft-delete + forced logout + login blocked afterwards)
 * is covered instead by tests/Feature/Web/WebProfileFlowTest.php against
 * a disposable factory-created user.
 */
test('deactivate-account form redisplays an error on the wrong password without deactivating the account', async () => {
    const bob = await loginAs(USERS.bob);
    const { page } = bob;

    await page.goto(absoluteUrl('/profile/deactivate'), { waitUntil: 'networkidle0' });

    await page.evaluate(() => {
        const form = [...document.querySelectorAll('form')].find((f) => f.action.endsWith('/profile') && f.querySelector('input[name="password"]'));
        form.querySelector('input[name="password"]').value = 'DefinitelyWrongPass1!';
    });

    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => {
            const form = [...document.querySelectorAll('form')].find((f) => f.action.endsWith('/profile') && f.querySelector('input[name="password"]'));
            // Bypass the confirm() dialog (Puppeteer auto-dismisses
            // native dialogs by default, which would block form
            // submission) by submitting the form directly.
            form.submit();
        }),
    ]);

    const bodyText = await page.evaluate(() => document.body.textContent);
    assert(bodyText.includes('current password is incorrect'), 'expected the deactivate-account error to be shown on the page');

    await page.goto(absoluteUrl('/dashboard'), { waitUntil: 'networkidle0' });
    assert(page.url().includes('/dashboard'), 'expected bob to still be logged in (account must not have been deactivated)');

    assert(bob.errors.length === 0, `bob unexpected JS errors: ${JSON.stringify(bob.errors)}`);

    await closeSession(bob);
});

test('at a real mobile viewport, the header avatar is a visible entry point all the way to Switch/Add account', async () => {
    const alice = await loginAs(USERS.alice);
    const { page } = alice;
    await page.setViewport({ width: 375, height: 667 });

    await page.goto(absoluteUrl('/chat'), { waitUntil: 'networkidle0' });
    const avatarLink = await page.evaluate(() => {
        const link = document.querySelector('a[href$="/profile"]');
        if (!link) return null;
        const rect = link.getBoundingClientRect();
        return { visible: rect.width > 0 && rect.height > 0, top: rect.top, bottom: rect.bottom };
    });
    assert(avatarLink?.visible, 'expected the header avatar (the mobile entry point to Settings) to be visible at a real mobile viewport');

    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('a[href$="/profile"]'),
    ]);
    let bodyText = await page.evaluate(() => document.body.textContent);
    assert(bodyText.includes('Account & Security'), 'expected tapping the header avatar to land on the settings hub, listing Account & Security');

    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => [...document.querySelectorAll('a')].find((a) => a.textContent.includes('Account & Security')).click()),
    ]);
    bodyText = await page.evaluate(() => document.body.textContent);
    assert(bodyText.includes('Switch account') && bodyText.includes('Add account'), 'expected Switch account and Add account to be reachable and visible on the Account & Security page at a real mobile viewport');

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);

    await closeSession(alice);
});

test('push notification devices page lists a subscribed device and can revoke it', async () => {
    const carol = await loginAs(USERS.carol);
    const { page } = carol;

    // Register a subscription via the real API (a fake token is fine
    // here - this test is about the list/revoke UI, not the real
    // browser subscribe flow, which is covered by push-notifications.test.js).
    const fcmToken = 'fake-fcm-token-settings-list-' + Date.now();
    await page.evaluate((token) => axios.post('/api/v1/push-subscriptions', { fcm_token: token }), fcmToken);

    await page.goto(absoluteUrl('/profile/push-subscriptions'), { waitUntil: 'networkidle0' });
    let bodyText = await page.evaluate(() => document.body.textContent);
    assert(bodyText.includes('Subscribed'), 'expected the newly subscribed device to be listed');
    assert(!bodyText.includes('No devices are subscribed'), 'expected the empty-state message to be gone once a device is subscribed');

    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => {
            const form = [...document.querySelectorAll('form')].find((f) => f.action.includes('/push-subscriptions/'));
            form.submit();
        }),
    ]);

    bodyText = await page.evaluate(() => document.body.textContent);
    assert(bodyText.includes('Push notifications turned off for that device.'), 'expected a status message confirming the device was revoked');

    // No GET /api/v1/push-subscriptions endpoint exists - verify via
    // the page itself instead, which re-renders from the DB.
    await page.goto(absoluteUrl('/profile/push-subscriptions'), { waitUntil: 'networkidle0' });
    const afterText = await page.evaluate(() => document.body.textContent);
    assert(afterText.includes('No devices are subscribed'), 'expected the device to no longer be listed after revoking');

    assert(carol.errors.length === 0, `carol unexpected JS errors: ${JSON.stringify(carol.errors)}`);

    await closeSession(carol);
});
