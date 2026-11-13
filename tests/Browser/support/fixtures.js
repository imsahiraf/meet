import { loginAs, closeSession, absoluteUrl } from './session.js';

export const FULL_NAMES = {
    'browser.alice@example.test': 'Browser Alice',
    'browser.bob@example.test': 'Browser Bob',
    'browser.carol@example.test': 'Browser Carol',
};

/**
 * Idempotently makes two users mutual contacts, using their own
 * isolated sessions internally. Safe to call from any test file
 * without assuming another file already ran first - test files must
 * not depend on cross-file execution order.
 */
export async function ensureContacts(emailA, emailB) {
    const a = await loginAs(emailA);
    const nameB = FULL_NAMES[emailB];

    await a.page.goto(absoluteUrl(`/contacts?q=${encodeURIComponent(nameB)}`), { waitUntil: 'networkidle0' });
    const status = await a.page.evaluate((name) => {
        const li = [...document.querySelectorAll('li')].find((el) => el.textContent.includes(name));
        if (!li) return 'not_found';
        if (li.textContent.includes('Already a contact')) return 'already_contact';
        if (li.textContent.includes('Request sent')) return 'pending_sent';
        if (li.textContent.includes('Respond to request')) return 'pending_received';
        return 'none';
    }, nameB);

    if (status === 'already_contact') {
        await closeSession(a);
        return;
    }

    if (status === 'none') {
        await a.page.evaluate(() => {
            const form = [...document.querySelectorAll('form')].find((f) => f.action.includes('/contacts/request'));
            form?.submit();
        });
        await new Promise((r) => setTimeout(r, 500));
    }

    // Whoever the current recipient is, accept from their side.
    const b = await loginAs(emailB);
    await b.page.goto(absoluteUrl('/contacts/requests'), { waitUntil: 'networkidle0' });
    await b.page.evaluate(() => {
        const forms = [...document.querySelectorAll('form')];
        const acceptForm = forms.find((f) => f.action.includes('/request/') && f.action.includes('/accept'));
        acceptForm?.submit();
    });
    await new Promise((r) => setTimeout(r, 500));

    await closeSession(a);
    await closeSession(b);
}

/**
 * Ensures emailA and emailB are contacts, then returns the 1:1
 * conversation URL between them (creating the conversation via
 * chat.start if it doesn't exist yet).
 */
export async function ensureConversationUrl(emailA, emailB) {
    await ensureContacts(emailA, emailB);

    const a = await loginAs(emailA);
    const nameB = FULL_NAMES[emailB];
    await a.page.goto(absoluteUrl('/contacts'), { waitUntil: 'networkidle0' });
    const chatHref = await a.page.evaluate((name) => {
        const link = [...document.querySelectorAll('a')].find((el) => el.textContent.includes(name));
        return link ? link.closest('li')?.querySelector('a[href*="/chat/start/"]')?.getAttribute('href') : null;
    }, nameB);

    if (!chatHref) {
        throw new Error(`Could not find a chat.start link to ${nameB} after ensuring contacts`);
    }

    // domcontentloaded, not networkidle0: an open conversation polls for
    // new messages on a sub-second interval, so there is never the 500ms
    // of total network silence networkidle0 waits for - it can only ever
    // time out on this page. All this navigation needs is the settled URL
    // after the /chat/start/{id} redirect.
    await a.page.goto(absoluteUrl(chatHref), { waitUntil: 'domcontentloaded' });
    const url = a.page.url();
    await closeSession(a);
    return url;
}
