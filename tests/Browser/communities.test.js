import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, absoluteUrl } from './support/session.js';
import { ensureContacts, FULL_NAMES } from './support/fixtures.js';

test('creating a community auto-creates an Announcements group, and attaching an owned group makes its members see community announcements', async () => {
    await ensureContacts(USERS.alice, USERS.bob);

    const alice = await loginAs(USERS.alice);
    const { page } = alice;

    const communityName = 'Test Community ' + Date.now();
    await page.goto(absoluteUrl('/communities/create'), { waitUntil: 'networkidle0' });
    await page.type('#name', communityName);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => document.querySelector('form[action$="/communities"]').submit()),
    ]);

    assert(page.url().includes('/communities/'), `expected to land on the new community's page, got ${page.url()}`);
    const bodyText = await page.evaluate(() => document.body.textContent);
    assert(bodyText.includes('Announcements'), 'expected the auto-created Announcements group to be listed');

    // Create a real group Alice owns with Bob as a member, then attach it.
    await page.goto(absoluteUrl('/groups/create'), { waitUntil: 'networkidle0' });
    const groupName = 'Attachable Group ' + Date.now();
    await page.type('#group_name', groupName);
    const bobName = FULL_NAMES[USERS.bob];
    await page.evaluate((name) => {
        const checkbox = [...document.querySelectorAll('input[name="members[]"]')].find((cb) => cb.closest('label')?.textContent.includes(name));
        checkbox.checked = true;
    }, bobName);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => document.querySelector('form[action$="/groups"]').submit()),
    ]);
    assert(page.url().includes('/groups/'), `expected to land on the new group's page, got ${page.url()}`);

    await page.goto(absoluteUrl('/communities'), { waitUntil: 'networkidle0' });
    const goToCommunity = await page.evaluate((name) => {
        const link = [...document.querySelectorAll('a')].find((a) => a.textContent.includes(name));
        if (link) { link.click(); return true; }
        return false;
    }, communityName);
    assert(goToCommunity, 'expected to find the created community in the communities list');
    await page.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {});

    await page.select('select[name="group_id"]', await page.$eval('select[name="group_id"] option:nth-child(2)', (el) => el.value));
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => document.querySelector('form[action$="/groups"]').submit()),
    ]);

    const afterAttach = await page.evaluate(() => document.body.textContent);
    assert(afterAttach.includes('Group added to the community'), 'expected a status message confirming the group was added');

    // Post a community announcement.
    const announcementTitle = 'Community Announcement ' + Date.now();
    await page.type('input[name="title"]', announcementTitle);
    await page.type('textarea[name="announcement"]', 'This is a community-wide update.');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => document.querySelector('form[action$="/announcements"]').submit()),
    ]);

    const communityPageUrl = page.url();
    await closeSession(alice);

    // Bob is a member of the attached group, so he should see the announcement.
    const bob = await loginAs(USERS.bob);
    await bob.page.goto(communityPageUrl, { waitUntil: 'networkidle0' });
    const bobBodyText = await bob.page.evaluate(() => document.body.textContent);
    assert(bobBodyText.includes(announcementTitle), 'expected Bob (member of an attached group) to see the community announcement');

    assert(bob.errors.length === 0, `bob unexpected JS errors: ${JSON.stringify(bob.errors)}`);
    await closeSession(bob);
});

test('the community owner can remove an attached group, and delete the whole community', async () => {
    await ensureContacts(USERS.alice, USERS.bob);

    const alice = await loginAs(USERS.alice);
    const { page } = alice;

    const communityName = 'Removable Community ' + Date.now();
    await page.goto(absoluteUrl('/communities/create'), { waitUntil: 'networkidle0' });
    await page.type('#name', communityName);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => document.querySelector('form[action$="/communities"]').submit()),
    ]);
    const communityUrl = page.url();

    // Create and attach a group.
    await page.goto(absoluteUrl('/groups/create'), { waitUntil: 'networkidle0' });
    const groupName = 'Group To Remove ' + Date.now();
    await page.type('#group_name', groupName);
    const bobName = FULL_NAMES[USERS.bob];
    await page.evaluate((name) => {
        const checkbox = [...document.querySelectorAll('input[name="members[]"]')].find((cb) => cb.closest('label')?.textContent.includes(name));
        checkbox.checked = true;
    }, bobName);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => document.querySelector('form[action$="/groups"]').submit()),
    ]);

    await page.goto(communityUrl, { waitUntil: 'networkidle0' });
    await page.select('select[name="group_id"]', await page.$eval('select[name="group_id"] option:nth-child(2)', (el) => el.value));
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => document.querySelector('form[action$="/groups"]').submit()),
    ]);

    const beforeDetachText = await page.evaluate(() => document.body.textContent);
    assert(beforeDetachText.includes(groupName), 'expected the attached group to be listed before removal');

    // Remove (detach) the group - bypass the confirm() dialog by
    // submitting the matching detach form directly, same convention
    // used elsewhere in this suite for destructive confirm()-gated actions.
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate((name) => {
            const forms = [...document.querySelectorAll('form')].filter((f) => f.action.includes('/groups/') && f.querySelector('button')?.textContent.includes('Remove'));
            const form = forms.find((f) => f.closest('li')?.textContent.includes(name));
            form.submit();
        }, groupName),
    ]);

    const afterDetachText = await page.evaluate(() => document.body.textContent);
    assert(afterDetachText.includes('Group removed from the community'), 'expected a status message confirming the group was removed');

    // The group itself still appears - now in the "add an existing
    // group you own" dropdown, since detaching makes it re-attachable -
    // so check it specifically by the absence of its list row, not by
    // the group name being absent from the page entirely.
    const stillListedAsAttached = await page.evaluate((name) => {
        return [...document.querySelectorAll('ul li')].some((li) => li.textContent.includes(name) && li.querySelector('a[href*="/groups/"]'));
    }, groupName);
    assert(!stillListedAsAttached, 'expected the removed group to no longer be listed as an attached group');

    // Delete the whole community.
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.evaluate(() => document.querySelector('form[action$="' + window.location.pathname.split('/').pop() + '"]')?.submit()
            || [...document.querySelectorAll('form')].find((f) => f.querySelector('button')?.textContent.includes('Delete community')).submit()),
    ]);

    assert(page.url().endsWith('/communities'), `expected to land back on the communities index after deleting, got ${page.url()}`);
    const indexText = await page.evaluate(() => document.body.textContent);
    assert(indexText.includes('Community deleted.'), 'expected a status message confirming the community was deleted');
    assert(!indexText.includes(communityName), 'expected the deleted community to no longer appear in the index');

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);
    await closeSession(alice);
});
