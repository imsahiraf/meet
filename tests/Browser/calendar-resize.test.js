import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS, absoluteUrl } from './support/session.js';

async function waitUntil(fn, { timeout = 10000, interval = 300, message = 'condition' } = {}) {
    const start = Date.now();
    while (Date.now() - start < timeout) {
        if (await fn()) return true;
        await new Promise((r) => setTimeout(r, interval));
    }
    throw new Error(`Timed out waiting for: ${message}`);
}

test('adding a personal calendar entry, then dragging its resize handle, changes only its duration', async () => {
    const alice = await loginAs(USERS.alice);
    const { page } = alice;

    await page.goto(absoluteUrl('/meetings/calendar'), { waitUntil: 'networkidle0' });

    // ---- Add a standalone (non-meeting) entry ----
    // +2h (not a fixed clock time) so it's reliably still "upcoming"
    // regardless of what time of day this test happens to run at.
    await page.click('#cal-add-event-btn');
    await page.type('#cal-add-title', 'Resize me');
    await page.click('input[name="cal-add-type"][value="task"]');

    const eventId = await page.evaluate(() => {
        return new Promise((resolve, reject) => {
            const start = new Date(Date.now() + 2 * 60 * 60000);
            start.setMinutes(0, 0, 0);
            const end = new Date(start.getTime() + 60 * 60000);
            const pad = (n) => String(n).padStart(2, '0');
            const toLocal = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
            document.getElementById('cal-add-start').value = toLocal(start);
            document.getElementById('cal-add-end').value = toLocal(end);
            document.getElementById('cal-add-save').click();

            const check = setInterval(async () => {
                try {
                    const response = await axios.get('/api/v1/calendar/events');
                    const found = response.data.data.find((e) => e.title === 'Resize me');
                    if (found) {
                        clearInterval(check);
                        resolve(found.id);
                    }
                } catch (e) { /* keep polling */ }
            }, 300);
            setTimeout(() => { clearInterval(check); reject(new Error('event never appeared')); }, 10000);
        });
    });
    assert(eventId, 'expected the standalone event to be created');

    const originalEnd = await page.evaluate((id) => axios.get('/api/v1/calendar/events').then((r) => r.data.data.find((e) => e.id === id).end_at), eventId);

    // ---- Switch to Day view, scroll the block into view, and drag its resize handle down ----
    await page.click('[data-cal-view="day"]');
    await waitUntil(async () => {
        const text = await page.$eval('#cal-day-body', (el) => el.textContent).catch(() => '');
        return text.includes('Resize me');
    }, { message: 'the new entry appears as a block in day view' });

    const blockBox = await page.evaluate(() => {
        // Event blocks are buttons (not links) - clicking one opens
        // the detail popup first instead of navigating straight into
        // joining a meeting; see openEventDetail() in calendar.blade.php.
        const block = [...document.querySelectorAll('#cal-day-body button')].find((b) => b.textContent.includes('Resize me'));
        block.scrollIntoView({ block: 'center' });
        const rect = block.getBoundingClientRect();
        return { x: rect.x + rect.width / 2, bottom: rect.bottom };
    });

    await page.mouse.move(blockBox.x, blockBox.bottom - 1);
    await page.mouse.down();
    await page.mouse.move(blockBox.x, blockBox.bottom + 48, { steps: 5 }); // +48px = +1 hour at 48px/hour
    await page.mouse.up();

    await waitUntil(async () => {
        const end = await page.evaluate((id) => axios.get('/api/v1/calendar/events').then((r) => {
            const found = r.data.data.find((e) => e.id === id);
            return found ? found.end_at : null;
        }), eventId);
        return end && end !== originalEnd;
    }, { message: 'the event\'s end_at to change after dragging the resize handle' });

    const afterResize = await page.evaluate((id) => axios.get('/api/v1/calendar/events').then((r) => r.data.data.find((e) => e.id === id)), eventId);
    assert(new Date(afterResize.end_at).getTime() > new Date(originalEnd).getTime(), 'expected the duration to have increased');

    const startUnchanged = await page.evaluate((id, expectedStart) => axios.get('/api/v1/calendar/events').then((r) => {
        const found = r.data.data.find((e) => e.id === id);
        return found && found.start_at === expectedStart;
    }), eventId, afterResize.start_at);
    assert(startUnchanged, 'expected start_at to be untouched by the resize');

    // ---- Delete it via the same DELETE endpoint the day-popover "Delete" button uses ----
    await page.evaluate((id) => axios.delete('/api/v1/calendar/events/' + id), eventId);
    const stillPresent = await page.evaluate((id) => axios.get('/api/v1/calendar/events').then((r) => r.data.data.some((e) => e.id === id)), eventId);
    assert(!stillPresent, 'expected the deleted entry to no longer appear in the upcoming events list');

    assert(alice.errors.length === 0, `alice unexpected JS errors: ${JSON.stringify(alice.errors)}`);

    await closeSession(alice);
});
