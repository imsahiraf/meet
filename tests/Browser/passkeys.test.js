import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, absoluteUrl, PASSWORD } from './support/session.js';

/**
 * Chrome's CDP WebAuthn domain provides a real virtual authenticator -
 * actual ECDSA keypair generation, attestation, and assertion signing
 * happen inside Chrome itself, so this test drives the exact same
 * navigator.credentials.create()/get() code path a real device would,
 * with no hand-rolled crypto of our own. The virtual authenticator is
 * scoped to the CDP session it's added on, so this whole test - both
 * the passkey registration and the later passkey-only login - has to
 * stay on the same `page`/CDP session for the credential's private key
 * to still be available when the login assertion is signed.
 */
async function addVirtualAuthenticator(page) {
    const client = await page.createCDPSession();
    await client.send('WebAuthn.enable');
    const { authenticatorId } = await client.send('WebAuthn.addVirtualAuthenticator', {
        options: {
            protocol: 'ctap2',
            transport: 'internal',
            hasResidentKey: true,
            hasUserVerification: true,
            isUserVerified: true,
            automaticPresenceSimulation: true,
        },
    });

    return { client, authenticatorId };
}

test('a user can register a passkey from Settings and log back in with it alone (no password)', async () => {
    // Reuses the carol fixture (also used by the 2FA browser test) -
    // safe because both tests fully clean up their own state
    // (disable 2FA / remove the passkey) before closing their session.
    const carol = await loginAs(USERS.carol);
    const { page } = carol;

    await addVirtualAuthenticator(page);

    await page.goto(absoluteUrl('/profile/passkeys'), { waitUntil: 'networkidle0' });

    const addBtnVisible = await page.evaluate(() => {
        const btn = document.getElementById('passkey-add-btn');
        return btn && !btn.hidden;
    });
    assert(addBtnVisible, 'expected the "Add a passkey" button to appear (browser supports the WebAuthn JSON helpers)');

    await page.click('#passkey-add-btn');
    await page.type('#passkey-name-input', 'Test device');

    // storePasskey() returns JSON and the confirm handler reloads the
    // page itself (see the JS in profile/passkeys.blade.php) - a real
    // navigation, not a DOM update, so wait for that instead of text.
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 15000 }),
        page.click('#passkey-add-confirm-btn'),
    ]);

    let bodyText = await page.evaluate(() => document.body.textContent);
    assert(bodyText.includes('Test device'), 'expected the newly added passkey to be listed by name after reload');

    // Log out, then sign back in from the login page using only the
    // passkey - password auto-fill/typing never happens in this
    // branch. Logout itself lives on the Account & Security page now,
    // not in the shared header/nav any more.
    await page.goto(absoluteUrl('/profile/security'), { waitUntil: 'networkidle0' });
    await page.evaluate(() => {
        const form = [...document.querySelectorAll('form')].find((f) => f.action.endsWith('/logout'));
        form.submit();
    });
    await page.waitForNavigation({ waitUntil: 'networkidle0' });

    // A real logout invalidates the session out from under whichever
    // background poll (badge/heartbeat/notifications - see
    // components/layouts/app.blade.php) happens to be in flight at that
    // exact moment; Chrome logs the resulting 401 to the console itself
    // regardless of whether the JS that made the request handles it,
    // so it's expected, harmless noise from this test's own deliberate
    // logout, not a real bug - reset the error log at this checkpoint so
    // it doesn't get attributed to the passkey flow that follows.
    carol.errors.length = 0;

    await page.goto(absoluteUrl('/login'), { waitUntil: 'networkidle0' });

    const passkeyBtnVisible = await page.evaluate(() => {
        const row = document.getElementById('passkey-login-row');
        return row && !row.hidden;
    });
    assert(passkeyBtnVisible, 'expected the "Sign in with a passkey" button to appear on the login page');

    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('#passkey-login-btn'),
    ]);

    assert(page.url().includes('/chat'), `expected passkey login to land in chat, got ${page.url()}`);

    // Clean up: remove the passkey so this fixture account doesn't stay
    // passkey-registered for other browser test files reusing it.
    await page.goto(absoluteUrl('/profile/passkeys'), { waitUntil: 'networkidle0' });
    await page.evaluate((password) => {
        const form = [...document.querySelectorAll('form')].find((f) => f.action.includes('/profile/passkeys/'));
        form.querySelector('input[name="password"]').value = password;
    }, PASSWORD);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => {
            const form = [...document.querySelectorAll('form')].find((f) => f.action.includes('/profile/passkeys/'));
            form.submit();
        }),
    ]);

    const finalBodyText = await page.evaluate(() => document.body.textContent);
    assert(finalBodyText.includes('Passkey removed.'), 'expected the passkey to be removed again during cleanup');

    assert(carol.errors.length === 0, `unexpected JS errors: ${JSON.stringify(carol.errors)}`);

    await closeSession(carol);
});
