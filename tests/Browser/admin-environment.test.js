import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, absoluteUrl } from './support/session.js';

/**
 * Deliberately does NOT exercise the "correct password" success path
 * here - unlike the PHPUnit suite (which binds EnvFileService to an
 * isolated temp file, see tests/Feature/Admin/EnvironmentManagementTest.php),
 * this runs against the real dev server's REAL .env file. Verifying
 * the page renders, the tabs work, and a wrong password is safely
 * rejected (no file write happens before Hash::check - see
 * AdminEnvironmentController) is exactly the ceiling of what's safe to
 * automate here without risking corrupting the actual project
 * environment file on every test run.
 */
test('admin environment page: renders real .env keys, tab switching works, and a wrong password is safely rejected with no write', async () => {
    const admin = await loginAs(USERS.admin);
    await admin.page.goto(absoluteUrl('/admin/environment'), { waitUntil: 'networkidle0' });

    const pageState = await admin.page.evaluate(() => ({
        hasFieldsPanel: !!document.getElementById('env-panel-fields'),
        hasRawPanel: !!document.getElementById('env-panel-raw'),
        rawPanelHiddenInitially: document.getElementById('env-panel-raw').classList.contains('hidden'),
        fieldCount: document.querySelectorAll('#env-panel-fields input[name^="env["]').length,
    }));
    assert(pageState.hasFieldsPanel && pageState.hasRawPanel, 'expected both the field editor and raw editor panels to render');
    assert(pageState.rawPanelHiddenInitially, 'expected the raw editor to start hidden (field editor is the default tab)');
    assert(pageState.fieldCount > 0, 'expected at least one real env var field to render');

    // A non-admin never even sees the nav link and gets 403 outright.
    const alice = await loginAs(USERS.alice);
    const forbidden = await alice.page.goto(absoluteUrl('/admin/environment'));
    assert(forbidden.status() === 403, `expected a non-admin visiting the environment page to get 403, got ${forbidden.status()}`);
    await closeSession(alice);

    // Switching to the raw tab reveals it and hides the field editor.
    await admin.page.click('[data-env-tab="raw"]');
    const afterTabSwitch = await admin.page.evaluate(() => ({
        rawVisible: !document.getElementById('env-panel-raw').classList.contains('hidden'),
        fieldsHidden: document.getElementById('env-panel-fields').classList.contains('hidden'),
        rawTextareaHasContent: document.querySelector('#env-panel-raw textarea').value.length > 0,
    }));
    assert(afterTabSwitch.rawVisible, 'expected the raw editor panel to become visible after clicking its tab');
    assert(afterTabSwitch.fieldsHidden, 'expected the field editor panel to hide after switching tabs');
    assert(afterTabSwitch.rawTextareaHasContent, 'expected the raw textarea to be pre-filled with the real .env contents');

    // A sensitive-looking field starts masked, with a working Show/Hide toggle.
    await admin.page.click('[data-env-tab="fields"]');
    const toggleBtn = await admin.page.$('.env-toggle-visibility');
    if (toggleBtn) {
        const targetId = await admin.page.evaluate((btn) => btn.dataset.target, toggleBtn);
        const typeBefore = await admin.page.$eval('#' + targetId, (el) => el.type);
        assert(typeBefore === 'password', `expected a sensitive-looking key's field to start masked, got type="${typeBefore}"`);
        await toggleBtn.click();
        const typeAfter = await admin.page.$eval('#' + targetId, (el) => el.type);
        assert(typeAfter === 'text', 'expected the Show/Hide toggle to unmask the field');
    }

    // Wrong password on the field editor: rejected, and (critically)
    // must not touch the real file - AdminEnvironmentController
    // validates the password via Hash::check BEFORE ever calling
    // EnvFileService::set(), so this is safe to actually submit.
    await admin.page.type('input[name="password"]', 'definitely-the-wrong-password');
    await Promise.all([
        admin.page.waitForNavigation({ waitUntil: 'networkidle0' }),
        admin.page.click('#env-panel-fields button[type="submit"]'),
    ]);
    const errorShown = await admin.page.evaluate(() => document.body.textContent.includes('current password is incorrect'));
    assert(errorShown, 'expected a wrong password to show the standard step-up-auth error message');

    assert(admin.errors.length === 0, `admin unexpected JS errors: ${JSON.stringify(admin.errors)}`);
    await closeSession(admin);
});
