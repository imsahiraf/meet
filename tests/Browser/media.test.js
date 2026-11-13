import path from 'node:path';
import fs from 'node:fs';
import os from 'node:os';
import { execSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { test, assert } from './support/test.js';
import { loginAs, closeSession, USERS } from './support/session.js';
import { ensureConversationUrl } from './support/fixtures.js';

const dir = path.dirname(fileURLToPath(import.meta.url));
const IMAGE_FIXTURE = path.join(dir, 'fixtures/test-image.png');
const DOCUMENT_FIXTURE = path.join(dir, 'fixtures/test-document.txt');

function noErrors(session, label) {
    assert(session.errors.length === 0, `${label} had JS errors: ${JSON.stringify(session.errors)}`);
}

async function attachAndSend(page, filePath, { oneView = false, caption = null, kind = 'gallery' } = {}) {
    const fileInput = await page.$(kind === 'document' ? '#attachment-input-document' : '#attachment-input-gallery');
    await fileInput.uploadFile(filePath);
    await new Promise((r) => setTimeout(r, 300));

    const previewVisible = await page.evaluate(() => !document.getElementById('attachment-preview').classList.contains('hidden'));
    assert(previewVisible, 'attachment preview banner should appear after selecting a file');

    if (oneView) {
        await page.click('#one-view-toggle');
    }
    if (caption) {
        await page.type('#composer-input', caption);
    }

    await page.click('#composer button[type="submit"]');
    await new Promise((r) => setTimeout(r, 1500));
}

test('media: sending an image attachment renders as an image for both parties', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    await attachAndSend(alice.page, IMAGE_FIXTURE, { caption: `Image caption ${Date.now()}` });

    const aliceHasImg = await alice.page.evaluate(() => document.querySelectorAll('#messages img').length > 0);
    assert(aliceHasImg, 'Alice should see her own uploaded image rendered as an <img>');

    const previewCleared = await alice.page.evaluate(() => document.getElementById('attachment-preview').classList.contains('hidden'));
    assert(previewCleared, 'attachment preview should clear after sending');

    await bob.page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    await new Promise((r) => setTimeout(r, 1200));
    const bobHasImg = await bob.page.evaluate(() => document.querySelectorAll('#messages img').length > 0);
    assert(bobHasImg, 'Bob should see the image Alice sent, rendered as an <img>');

    // Tapping the bubble image opens a fully custom-built lightbox
    // viewer (never the browser's own native image viewer) - see
    // openImageViewer() in show.blade.php. This conversation is reused
    // across every test in this file, so scope to the LAST
    // (just-sent) image, not the first.
    assert(await alice.page.evaluate(() => document.getElementById('media-viewer-modal').classList.contains('hidden')), 'lightbox should start closed');
    const imgSrc = await alice.page.evaluate(() => [...document.querySelectorAll('#messages img')].pop().src);
    await alice.page.evaluate(() => [...document.querySelectorAll('#messages img')].pop().click());
    const lightbox = await alice.page.evaluate(() => ({
        visible: !document.getElementById('media-viewer-modal').classList.contains('hidden'),
        imageSrc: document.getElementById('media-viewer-image').src,
    }));
    assert(lightbox.visible, 'expected tapping the image bubble to open the custom lightbox viewer');
    assert(lightbox.imageSrc === imgSrc, `expected the lightbox to show the same image, got "${lightbox.imageSrc}" vs "${imgSrc}"`);
    await alice.page.click('#media-viewer-close');
    assert(await alice.page.evaluate(() => document.getElementById('media-viewer-modal').classList.contains('hidden')), 'expected the close button to dismiss the lightbox');

    noErrors(alice, 'media image (alice)');
    noErrors(bob, 'media image (bob)');
    await closeSession(alice);
    await closeSession(bob);
});

test('media: sending a document attachment renders as a download link', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);

    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    await attachAndSend(alice.page, DOCUMENT_FIXTURE, { kind: 'document' });

    const hasDownloadLink = await alice.page.evaluate(() => {
        const link = [...document.querySelectorAll('#messages a')].find((a) => a.textContent.includes('test-document.txt'));
        return !!link;
    });
    assert(hasDownloadLink, 'expected a download link for the uploaded document, named after the original file');

    noErrors(alice, 'media document');
    await closeSession(alice);
});

test('voice recording: the WhatsApp-style bar shows a live waveform and elapsed time, and the green arrow sends immediately', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    const normalRowVisibleBefore = await alice.page.evaluate(() => !document.getElementById('composer-normal-row').classList.contains('hidden'));
    assert(normalRowVisibleBefore, 'expected the normal composer row to be visible before recording starts');

    await alice.page.click('#voice-note-button');
    await new Promise((r) => setTimeout(r, 800));

    const barState = await alice.page.evaluate(() => ({
        normalRowHidden: document.getElementById('composer-normal-row').classList.contains('hidden'),
        recordingBarVisible: !document.getElementById('voice-recording-bar').classList.contains('hidden'),
        waveformBarCount: document.getElementById('voice-waveform').children.length,
    }));
    assert(barState.normalRowHidden, 'expected the normal composer row to hide once recording starts');
    assert(barState.recordingBarVisible, 'expected the WhatsApp-style recording bar to appear once recording starts');
    assert(barState.waveformBarCount > 0, 'expected the recording bar to render a waveform');

    // A real live waveform sampled from the actual (fake) mic stream via
    // Web Audio's AnalyserNode should change over time, not sit static.
    const waveformSnapshotA = await alice.page.evaluate(() => [...document.getElementById('voice-waveform').children].map((el) => el.style.height));
    await new Promise((r) => setTimeout(r, 900));
    const waveformSnapshotB = await alice.page.evaluate(() => [...document.getElementById('voice-waveform').children].map((el) => el.style.height));
    assert(JSON.stringify(waveformSnapshotA) !== JSON.stringify(waveformSnapshotB), 'expected the waveform to actually animate/change over time, not stay static');

    const elapsedAfter800ms = await alice.page.evaluate(() => document.getElementById('voice-recording-time').textContent);
    assert(elapsedAfter800ms !== '0:00', `expected the elapsed-time counter to have advanced, still showed "${elapsedAfter800ms}"`);

    // Pause freezes the timer.
    await alice.page.click('#voice-recording-pause');
    const timeAtPause = await alice.page.evaluate(() => document.getElementById('voice-recording-time').textContent);
    await new Promise((r) => setTimeout(r, 900));
    const timeStillPaused = await alice.page.evaluate(() => document.getElementById('voice-recording-time').textContent);
    assert(timeAtPause === timeStillPaused, `expected the timer to freeze while paused, went from "${timeAtPause}" to "${timeStillPaused}"`);
    const pauseLabel = await alice.page.evaluate(() => document.getElementById('voice-recording-pause').textContent.trim());
    assert(pauseLabel === 'Resume', `expected the pause button to flip to "Resume" once paused, got "${pauseLabel}"`);

    await alice.page.click('#voice-recording-pause'); // resume
    await new Promise((r) => setTimeout(r, 500));

    // The green send arrow stops and sends immediately - no separate
    // preview/confirm step for a voice note.
    await alice.page.click('#voice-recording-send');
    await new Promise((r) => setTimeout(r, 1500));

    const afterSend = await alice.page.evaluate(() => ({
        hasAudio: document.querySelectorAll('#messages audio').length > 0,
        recordingBarHidden: document.getElementById('voice-recording-bar').classList.contains('hidden'),
        normalRowVisible: !document.getElementById('composer-normal-row').classList.contains('hidden'),
    }));
    assert(afterSend.hasAudio, 'expected an <audio> element after the voice note was sent');
    assert(afterSend.recordingBarHidden, 'expected the recording bar to hide again after sending');
    assert(afterSend.normalRowVisible, 'expected the normal composer row to be restored after sending');

    // Fully custom-built playback bar - never the browser's own native
    // <audio controls> - see createAudioPlayer() in show.blade.php.
    // This conversation is reused across every test in this file, so
    // scope to the LAST (just-sent) audio message, not the first.
    const player = await alice.page.evaluate(() => {
        var audio = [...document.querySelectorAll('#messages audio')].pop();
        var wrap = audio.closest('.custom-audio-player');
        return {
            hasControlsAttr: audio.hasAttribute('controls'),
            hasWrapper: !!wrap,
            hasPlayButton: !!(wrap && wrap.querySelector('button')),
            hasSeekBar: !!(wrap && wrap.querySelector('input[type="range"]')),
        };
    });
    assert(!player.hasControlsAttr, 'expected the <audio> element to have NO native controls attribute');
    assert(player.hasWrapper, 'expected the audio to be wrapped in the custom player container');
    assert(player.hasPlayButton, 'expected a custom play/pause button');
    assert(player.hasSeekBar, 'expected a custom seek bar');

    // Clicking the custom play button actually plays the real recorded
    // audio (not just a UI toggle with no effect).
    await alice.page.evaluate(() => [...document.querySelectorAll('#messages audio')].pop().closest('.custom-audio-player').querySelector('button').click());
    await new Promise((r) => setTimeout(r, 300));
    const playingState = await alice.page.evaluate(() => ![...document.querySelectorAll('#messages audio')].pop().paused);
    assert(playingState, 'expected clicking the custom play button to actually start real audio playback');

    await alice.page.evaluate(() => [...document.querySelectorAll('#messages audio')].pop().closest('.custom-audio-player').querySelector('button').click());
    await new Promise((r) => setTimeout(r, 200));
    const pausedState = await alice.page.evaluate(() => [...document.querySelectorAll('#messages audio')].pop().paused);
    assert(pausedState, 'expected clicking the button again to pause playback');

    noErrors(alice, 'voice note recording bar');
    await closeSession(alice);
});

test('voice recording: the trash button discards the recording without sending anything', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    const audioCountBefore = await alice.page.evaluate(() => document.querySelectorAll('#messages audio').length);

    await alice.page.click('#voice-note-button');
    await new Promise((r) => setTimeout(r, 700));
    await alice.page.click('#voice-recording-cancel');
    await new Promise((r) => setTimeout(r, 500));

    const afterCancel = await alice.page.evaluate(() => ({
        recordingBarHidden: document.getElementById('voice-recording-bar').classList.contains('hidden'),
        normalRowVisible: !document.getElementById('composer-normal-row').classList.contains('hidden'),
        previewHidden: document.getElementById('attachment-preview').classList.contains('hidden'),
        audioCount: document.querySelectorAll('#messages audio').length,
    }));
    assert(afterCancel.recordingBarHidden, 'expected the recording bar to hide after discarding');
    assert(afterCancel.normalRowVisible, 'expected the normal composer row to be restored after discarding');
    assert(afterCancel.previewHidden, 'expected no attachment preview to appear from a discarded recording');
    assert(afterCancel.audioCount === audioCountBefore, 'expected discarding to never send anything');

    noErrors(alice, 'voice note cancel');
    await closeSession(alice);
});

test('voice recording: sliding the pill left past the threshold discards the recording, same as the trash button', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    const audioCountBefore = await alice.page.evaluate(() => document.querySelectorAll('#messages audio').length);

    await alice.page.click('#voice-note-button');
    await new Promise((r) => setTimeout(r, 700));

    const pillBox = await alice.page.evaluate(() => {
        const rect = document.getElementById('voice-recording-pill').getBoundingClientRect();
        return { x: rect.x + rect.width / 2, y: rect.y + rect.height / 2 };
    });

    // A short drag (under the threshold) must NOT cancel - it should
    // snap back and let recording continue.
    await alice.page.mouse.move(pillBox.x, pillBox.y);
    await alice.page.mouse.down();
    await alice.page.mouse.move(pillBox.x - 30, pillBox.y, { steps: 3 });
    await new Promise((r) => setTimeout(r, 100));
    const hintDuringShortDrag = await alice.page.evaluate(() => document.getElementById('voice-slide-cancel-hint').classList.contains('flex'));
    assert(hintDuringShortDrag, 'expected the "Slide to cancel" hint to appear once dragging starts');

    await alice.page.mouse.up();
    await new Promise((r) => setTimeout(r, 200));
    const stillRecordingAfterShortDrag = await alice.page.evaluate(() => !document.getElementById('voice-recording-bar').classList.contains('hidden'));
    assert(stillRecordingAfterShortDrag, 'expected a short drag (under the cancel threshold) to snap back without cancelling');

    // Now drag well past the threshold and release.
    await alice.page.mouse.move(pillBox.x, pillBox.y);
    await alice.page.mouse.down();
    await alice.page.mouse.move(pillBox.x - 120, pillBox.y, { steps: 5 });
    await new Promise((r) => setTimeout(r, 100));
    await alice.page.mouse.up();
    await new Promise((r) => setTimeout(r, 500));

    const afterCancel = await alice.page.evaluate(() => ({
        recordingBarHidden: document.getElementById('voice-recording-bar').classList.contains('hidden'),
        audioCount: document.querySelectorAll('#messages audio').length,
    }));
    assert(afterCancel.recordingBarHidden, 'expected sliding past the threshold to discard the recording (bar hidden)');
    assert(afterCancel.audioCount === audioCountBefore, 'expected the slide-to-cancel gesture to never send anything');

    noErrors(alice, 'voice note slide-to-cancel');
    await closeSession(alice);
});

test('one view: receiver can view exactly once; once consumed, neither party can reopen it', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    // Viewing is gated to the installed PWA (see ensureOneViewAllowed())
    // - this test is about the actual view-once behavior, not that
    // gate, so bob's page reports as standalone the same way a real
    // installed app would.
    await bob.page.evaluateOnNewDocument(() => {
        Object.defineProperty(window, 'matchMedia', {
            value: (query) => ({
                matches: query.includes('standalone'),
                media: query,
                addListener() {},
                removeListener() {},
                addEventListener() {},
                removeEventListener() {},
            }),
        });
    });

    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    await attachAndSend(alice.page, IMAGE_FIXTURE, { oneView: true });

    // Before anyone has consumed it, the sender sees a plain, non-clickable
    // "One View" label - never a "Tap to view once" button, and never able
    // to re-preview their own media (matches real WhatsApp).
    await alice.page.reload({ waitUntil: 'networkidle0' });
    const aliceLabelBefore = await alice.page.evaluate(() => {
        const el = [...document.querySelectorAll('#messages div')].find((d) => d.textContent.includes('One View'));
        return el ? el.textContent.trim() : null;
    });
    assert(aliceLabelBefore && aliceLabelBefore.includes('One View'), `sender should see a plain non-clickable "One View" label before anyone has viewed it, got "${aliceLabelBefore}"`);
    const aliceHasNoViewButton = await alice.page.evaluate(() => {
        return ![...document.querySelectorAll('#messages button')].some((b) => b.textContent.includes('view'));
    });
    assert(aliceHasNoViewButton, 'sender must not have any clickable "view" button for their own View Once media');
    // The backend itself now rejects the sender's own view attempt
    // outright (403) too, not just the UI hiding the button - covered
    // directly in MediaFlowTest::test_one_view_media_can_only_be_viewed_once_by_the_receiver.

    await bob.page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    await new Promise((r) => setTimeout(r, 1200));

    await bob.page.evaluate(() => {
        const btn = [...document.querySelectorAll('#messages button')].find((b) => b.textContent.includes('view'));
        if (!btn) throw new Error('one-view placeholder button not found for Bob');
        btn.click();
    });
    await new Promise((r) => setTimeout(r, 800));

    // Opens in the full-screen modal (WhatsApp-style), not inline in
    // the chat bubble.
    const modalState = await bob.page.evaluate(() => ({
        visible: !document.getElementById('one-view-modal').classList.contains('hidden'),
        hasImg: document.querySelectorAll('#one-view-modal-content img').length > 0,
    }));
    assert(modalState.visible, 'expected the One View modal to open after tapping to view');
    assert(modalState.hasImg, 'expected the image to be revealed inside the modal');

    // Best-effort leak deterrents: not draggable/selectable, right-click
    // blocked, and the media is hidden behind a guard the instant the
    // tab loses focus (the one thing a page can actually detect - this
    // covers the OS app-switcher preview, not a real screenshot/screen-
    // recording block, which no browser exposes to a web page at all).
    const protections = await bob.page.evaluate(() => {
        const img = document.querySelector('#one-view-modal-content img');
        const modal = document.getElementById('one-view-modal');
        const contextEvent = new MouseEvent('contextmenu', { bubbles: true, cancelable: true });
        modal.dispatchEvent(contextEvent);
        return {
            draggable: img.getAttribute('draggable'),
            userSelect: getComputedStyle(modal).userSelect,
            contextMenuBlocked: contextEvent.defaultPrevented,
        };
    });
    assert(protections.draggable === 'false', 'expected the One View image to be non-draggable');
    assert(protections.userSelect === 'none', `expected the One View modal to disable text/image selection, got "${protections.userSelect}"`);
    assert(protections.contextMenuBlocked, 'expected right-click/context-menu to be blocked inside the One View modal');

    const hiddenGuardReacts = await bob.page.evaluate(() => {
        Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true });
        document.dispatchEvent(new Event('visibilitychange'));
        const guardShown = !document.getElementById('one-view-hidden-guard').classList.contains('hidden');
        Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true });
        document.dispatchEvent(new Event('visibilitychange'));
        const guardHiddenAgain = document.getElementById('one-view-hidden-guard').classList.contains('hidden');
        return guardShown && guardHiddenAgain;
    });
    assert(hiddenGuardReacts, 'expected the hidden-guard overlay to cover the media on visibilitychange=hidden and uncover it again once visible');

    await bob.page.click('#one-view-modal-close');
    const modalClosed = await bob.page.evaluate(() => document.getElementById('one-view-modal').classList.contains('hidden'));
    assert(modalClosed, 'expected the close button to dismiss the modal');

    // Reload Bob's page - the message should now show as "Opened" (a
    // plain label, not a button - there's nothing left to tap).
    await bob.page.reload({ waitUntil: 'networkidle0' });
    const bobOpenedText = await bob.page.evaluate(() => {
        const el = [...document.querySelectorAll('#messages div')].find((d) => d.textContent.trim() === 'Opened');
        return el ? el.textContent.trim() : null;
    });
    assert(bobOpenedText === 'Opened', `expected Bob's view to show "Opened" once consumed, got "${bobOpenedText}"`);

    // The file is genuinely deleted server-side once consumed - the
    // sender's own reload now also shows "Opened", not a perpetually
    // clickable placeholder for a file that no longer exists.
    await alice.page.reload({ waitUntil: 'networkidle0' });
    const aliceOpenedText = await alice.page.evaluate(() => {
        const el = [...document.querySelectorAll('#messages div')].find((d) => d.textContent.trim() === 'Opened');
        return el ? el.textContent.trim() : null;
    });
    assert(aliceOpenedText === 'Opened', `expected the sender to also see "Opened" once the file is genuinely consumed/deleted, got "${aliceOpenedText}"`);

    noErrors(alice, 'one-view (alice)');
    noErrors(bob, 'one-view (bob)');
    await closeSession(alice);
    await closeSession(bob);
});

test('one view: opening it in a plain browser tab (not the installed PWA) is blocked with an install prompt instead', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    const bob = await loginAs(USERS.bob);

    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    await attachAndSend(alice.page, IMAGE_FIXTURE, { oneView: true });

    // A plain tab: no beforeinstallprompt captured and not iOS, so the
    // gate falls through to the generic "look for an install option"
    // message rather than either of the more specific flows.
    await bob.page.evaluateOnNewDocument(() => {
        window.__meetInstallPrompt = null;
        Object.defineProperty(navigator, 'userAgent', { value: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', configurable: true });
    });
    await bob.page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    await new Promise((r) => setTimeout(r, 1200));

    await bob.page.evaluate(() => {
        const btn = [...document.querySelectorAll('#messages button')].find((b) => b.textContent.includes('view'));
        if (!btn) throw new Error('one-view placeholder button not found for Bob');
        btn.click();
    });
    await new Promise((r) => setTimeout(r, 300));

    const state = await bob.page.evaluate(() => ({
        modalHidden: document.getElementById('one-view-modal').classList.contains('hidden'),
        dialogVisible: !document.getElementById('meet-dialog-overlay').classList.contains('hidden'),
        dialogText: document.getElementById('meet-dialog-message').textContent,
    }));

    assert(state.modalHidden, 'expected the One View modal to stay closed in a plain (non-installed) browser tab');
    assert(state.dialogVisible, 'expected the install-prompt dialog to show instead');
    assert(state.dialogText.includes('installed app'), `expected the dialog to explain installing the app, got "${state.dialogText}"`);

    // Dismissing the prompt leaves the item still unconsumed - it must
    // still be viewable once the gate is actually satisfied.
    await bob.page.click('#meet-dialog-ok');

    noErrors(alice, 'one-view PWA gate (alice)');
    noErrors(bob, 'one-view PWA gate (bob)');
    await closeSession(alice);
    await closeSession(bob);
});

test('media: selecting multiple files from the same picker sends each as its own message, in order, in one go', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);

    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });
    // This conversation is reused across every test in this file, so
    // count against a before/after baseline rather than an absolute
    // number - other tests' images from earlier in the run are still
    // in the same thread history.
    const imgCountBefore = await alice.page.evaluate(() => document.querySelectorAll('#messages img').length);

    // Gallery and Document are now separate pickers (WhatsApp-style),
    // each still supporting a real multi-select within itself - two
    // images through the Gallery input here.
    const fileInput = await alice.page.$('#attachment-input-gallery');
    await fileInput.uploadFile(IMAGE_FIXTURE, IMAGE_FIXTURE);
    await new Promise((r) => setTimeout(r, 300));

    const thumbCount = await alice.page.evaluate(() => document.getElementById('attachment-preview-thumbs').children.length);
    assert(thumbCount === 2, `expected 2 attachment preview thumbnails, got ${thumbCount}`);

    const previewText = await alice.page.evaluate(() => document.getElementById('attachment-preview-text').textContent);
    assert(previewText.includes('2'), `expected the preview text to mention 2 files selected, got "${previewText}"`);

    await alice.page.click('#composer button[type="submit"]');
    await new Promise((r) => setTimeout(r, 2000));

    const result = await alice.page.evaluate(() => ({
        imgCount: document.querySelectorAll('#messages img').length,
        previewHidden: document.getElementById('attachment-preview').classList.contains('hidden'),
    }));
    assert(result.imgCount - imgCountBefore === 2, `expected both images to have been sent as their own separate messages, got ${result.imgCount - imgCountBefore} new <img> elements`);
    assert(result.previewHidden, 'expected the attachment preview to clear once both files finished sending');

    noErrors(alice, 'multi-attachment');
    await closeSession(alice);
});

test('compose preview: tapping a selected image before sending opens a full-size preview with Next/Previous through the other selected images', async () => {
    // Real gap fixed here: selecting several images to send only ever
    // showed a tiny 56px thumbnail strip with no way to see any of them
    // larger before sending - tapping one now opens the same full-size
    // viewer/carousel an already-sent image gets, navigating through
    // just this batch's own selected images, not the whole thread.
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    const fileInput = await alice.page.$('#attachment-input-gallery');
    await fileInput.uploadFile(IMAGE_FIXTURE, IMAGE_FIXTURE, IMAGE_FIXTURE);
    await new Promise((r) => setTimeout(r, 300));

    const thumbCount = await alice.page.evaluate(() => document.getElementById('attachment-preview-thumbs').children.length);
    assert(thumbCount === 3, `expected 3 attachment preview thumbnails, got ${thumbCount}`);

    const thumbSrcs = await alice.page.evaluate(() => [...document.querySelectorAll('#attachment-preview-thumbs img')].map((el) => el.src));
    assert(thumbSrcs.length === 3, `expected 3 clickable image thumbnails, got ${thumbSrcs.length}`);

    // Tap the FIRST thumbnail.
    await alice.page.evaluate(() => document.querySelectorAll('#attachment-preview-thumbs img')[0].click());
    await new Promise((r) => setTimeout(r, 200));

    async function viewerState() {
        return alice.page.evaluate(() => ({
            visible: !document.getElementById('media-viewer-modal').classList.contains('hidden'),
            src: document.getElementById('media-viewer-image').src,
            counter: document.getElementById('media-viewer-counter').textContent,
        }));
    }

    const opened = await viewerState();
    assert(opened.visible, 'expected the preview modal to open after tapping a selected image');
    assert(opened.src === thumbSrcs[0], `expected the preview to show the tapped image first, got a mismatched src`);
    assert(opened.counter.includes('1') && opened.counter.includes('3'), `expected a "1 / 3" style counter, got "${opened.counter}"`);

    await alice.page.click('#media-viewer-next');
    await new Promise((r) => setTimeout(r, 150));
    const afterNext = await viewerState();
    assert(afterNext.src === thumbSrcs[1], 'expected Next to advance to the second selected image');

    await alice.page.click('#media-viewer-next');
    await new Promise((r) => setTimeout(r, 150));
    const afterNext2 = await viewerState();
    assert(afterNext2.src === thumbSrcs[2], 'expected Next again to advance to the third selected image');

    // Wraps at the end, same as the thread's own viewer.
    await alice.page.click('#media-viewer-next');
    await new Promise((r) => setTimeout(r, 150));
    const wrapped = await viewerState();
    assert(wrapped.src === thumbSrcs[0], 'expected Next past the last selected image to wrap back to the first');

    await alice.page.click('#media-viewer-close');
    const closed = await alice.page.evaluate(() => document.getElementById('media-viewer-modal').classList.contains('hidden'));
    assert(closed, 'expected the close button to dismiss the compose preview');

    // The images are still queued to send afterward - previewing them
    // must not consume or clear the pending attachments.
    const stillPending = await alice.page.evaluate(() => document.getElementById('attachment-preview-thumbs').children.length);
    assert(stillPending === 3, `expected all 3 images to still be queued to send after previewing, got ${stillPending}`);

    noErrors(alice, 'compose image preview');
    await closeSession(alice);
});

test('media viewer: opening one image from a multi-select send lets you Next/Previous through the thread, wrapping at the ends', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);

    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    // There's no server-side "batch" id tying a multi-select's files
    // back together (each becomes its own independent message - see
    // the previous test) - the viewer instead treats every img.cursor-
    // pointer bubble currently in #messages, in on-screen order, as
    // one continuous carousel. Count against a before/after baseline
    // since this conversation is reused by earlier tests in this file.
    const totalBefore = await alice.page.evaluate(() => document.querySelectorAll('#messages img.cursor-pointer').length);

    const fileInput = await alice.page.$('#attachment-input-gallery');
    await fileInput.uploadFile(IMAGE_FIXTURE, IMAGE_FIXTURE, IMAGE_FIXTURE);
    await new Promise((r) => setTimeout(r, 300));

    const thumbCount = await alice.page.evaluate(() => document.getElementById('attachment-preview-thumbs').children.length);
    assert(thumbCount === 3, `expected 3 attachment preview thumbnails, got ${thumbCount}`);

    await alice.page.click('#composer button[type="submit"]');
    await new Promise((r) => setTimeout(r, 3000));

    const afterSend = await alice.page.evaluate(() => ({
        total: document.querySelectorAll('#messages img.cursor-pointer').length,
        srcs: [...document.querySelectorAll('#messages img.cursor-pointer')].map((img) => img.src),
    }));
    assert(afterSend.total - totalBefore === 3, `expected 3 new images to have been sent as their own messages, got ${afterSend.total - totalBefore}`);

    const total = afterSend.total;
    const srcs = afterSend.srcs;
    const firstNewIndex = totalBefore; // index of the first of the 3 just-sent images in the full thread list

    assert(await alice.page.evaluate(() => document.getElementById('media-viewer-modal').classList.contains('hidden')), 'lightbox should start closed');

    // Tap the FIRST of the three just-sent images to open the viewer.
    await alice.page.evaluate((idx) => {
        document.querySelectorAll('#messages img.cursor-pointer')[idx].click();
    }, firstNewIndex);

    const opened = await alice.page.evaluate(() => ({
        visible: !document.getElementById('media-viewer-modal').classList.contains('hidden'),
        src: document.getElementById('media-viewer-image').src,
        counter: document.getElementById('media-viewer-counter').textContent,
        counterHidden: document.getElementById('media-viewer-counter').classList.contains('hidden'),
        nextHidden: document.getElementById('media-viewer-next').classList.contains('hidden'),
        prevHidden: document.getElementById('media-viewer-prev').classList.contains('hidden'),
    }));
    assert(opened.visible, 'expected tapping an image to open the viewer');
    assert(opened.src === srcs[firstNewIndex], 'expected the viewer to open on the tapped image');
    assert(!opened.counterHidden, 'expected a position counter ("N / total") to be visible when the thread has multiple images');
    assert(opened.counter === `${firstNewIndex + 1} / ${total}`, `expected counter "${firstNewIndex + 1} / ${total}", got "${opened.counter}"`);
    assert(!opened.nextHidden && !opened.prevHidden, 'expected both Next and Previous chevrons to be visible with multiple images in the thread');

    // Next -> the second of the three just-sent images.
    await alice.page.click('#media-viewer-next');
    let state = await alice.page.evaluate(() => ({
        src: document.getElementById('media-viewer-image').src,
        counter: document.getElementById('media-viewer-counter').textContent,
    }));
    assert(state.src !== opened.src, 'Next should actually change the displayed image src');
    assert(state.src === srcs[firstNewIndex + 1], 'Next should move to the next image in the thread, in order');
    assert(state.counter === `${firstNewIndex + 2} / ${total}`, `expected counter "${firstNewIndex + 2} / ${total}", got "${state.counter}"`);

    // Next -> the third (and last) just-sent image, also the very last image in the whole thread.
    await alice.page.click('#media-viewer-next');
    const atLast = await alice.page.evaluate(() => document.getElementById('media-viewer-image').src);
    assert(atLast === srcs[total - 1], 'Next should move to the last image in the thread');

    // Next again from the last image wraps around to the first image in the thread (no dead-end).
    await alice.page.click('#media-viewer-next');
    const wrappedForward = await alice.page.evaluate(() => ({
        src: document.getElementById('media-viewer-image').src,
        counter: document.getElementById('media-viewer-counter').textContent,
    }));
    assert(wrappedForward.src === srcs[0], 'Next from the last image should wrap around to the first image in the thread');
    assert(wrappedForward.counter === `1 / ${total}`, `expected counter "1 / ${total}" after wrapping forward, got "${wrappedForward.counter}"`);

    // Previous from the first image wraps back to the last image.
    await alice.page.click('#media-viewer-prev');
    const wrappedBack = await alice.page.evaluate(() => ({
        src: document.getElementById('media-viewer-image').src,
        counter: document.getElementById('media-viewer-counter').textContent,
    }));
    assert(wrappedBack.src === srcs[total - 1], 'Previous from the first image should wrap around to the last image in the thread');
    assert(wrappedBack.counter === `${total} / ${total}`, `expected counter "${total} / ${total}" after wrapping back, got "${wrappedBack.counter}"`);

    // Previous twice more steps back to the originally-tapped image.
    await alice.page.click('#media-viewer-prev');
    await alice.page.click('#media-viewer-prev');
    const backToStart = await alice.page.evaluate(() => document.getElementById('media-viewer-image').src);
    assert(backToStart === srcs[firstNewIndex], 'stepping Previous back around the wrap should return to the originally-tapped image');

    await alice.page.click('#media-viewer-close');
    assert(await alice.page.evaluate(() => document.getElementById('media-viewer-modal').classList.contains('hidden')), 'expected Close to still dismiss the viewer after navigating');

    noErrors(alice, 'media viewer carousel');
    await closeSession(alice);
});

test('media viewer: a real two-finger pinch zooms the image, double-tap toggles 2x zoom, and a drag pans while zoomed in', async () => {
    // Real gap: Next/Previous existed, but nothing let you actually
    // make an image bigger/smaller inside the viewer at all - the
    // global viewport meta (maximum-scale=1, user-scalable=no) that
    // stops the whole PAGE from pinch-zooming applies here too, so this
    // has to be verified as a fully custom, hand-rolled gesture rather
    // than the browser's own. IMAGE_FIXTURE is a 1x1 pixel PNG (fine
    // for the other tests in this file, which only care about it being
    // AN image) - too small to give panning any real room to move, so
    // this generates its own modestly-sized fixture rather than reusing
    // it.
    const zoomFixture = path.join(os.tmpdir(), 'meet-test-zoom-photo-' + Date.now() + '.png');
    execSync(`php8.4 -r '
        $im = imagecreatetruecolor(800, 600);
        imagefill($im, 0, 0, imagecolorallocate($im, 80, 120, 200));
        imagepng($im, ${JSON.stringify(zoomFixture)});
        imagedestroy($im);
    '`);

    try {
        const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
        const alice = await loginAs(USERS.alice);
        await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

        await attachAndSend(alice.page, zoomFixture, { caption: `Pinch zoom target ${Date.now()}` });

        await alice.page.evaluate(() => {
            const imgs = document.querySelectorAll('#messages img.cursor-pointer');
            imgs[imgs.length - 1].click();
        });
        await new Promise((r) => setTimeout(r, 300));

        async function currentTransform() {
            return alice.page.evaluate(() => document.getElementById('media-viewer-image').style.transform);
        }

        const before = await currentTransform();
        assert(before === '', `expected no zoom transform before any gesture, got "${before}"`);

        const center = await alice.page.evaluate(() => {
            const rect = document.getElementById('media-viewer-image').getBoundingClientRect();
            return { x: rect.x + rect.width / 2, y: rect.y + rect.height / 2 };
        });

        const client = await alice.page.createCDPSession();

        // Two fingers 40px apart, spreading to 320px apart - a real
        // pinch-out gesture.
        await client.send('Input.dispatchTouchEvent', {
            type: 'touchStart',
            touchPoints: [{ x: center.x - 20, y: center.y, id: 0 }, { x: center.x + 20, y: center.y, id: 1 }],
        });
        await client.send('Input.dispatchTouchEvent', {
            type: 'touchMove',
            touchPoints: [{ x: center.x - 160, y: center.y, id: 0 }, { x: center.x + 160, y: center.y, id: 1 }],
        });
        await new Promise((r) => setTimeout(r, 100));
        await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });

        const afterPinch = await currentTransform();
        const pinchMatch = afterPinch.match(/scale\(([\d.]+)\)/);
        assert(pinchMatch, `expected a scale(...) transform after pinching out, got "${afterPinch}"`);
        assert(parseFloat(pinchMatch[1]) > 1.5, `expected the pinch to have zoomed in meaningfully (>1.5x), got scale ${pinchMatch[1]}`);

        // Pinching back below 1x must clamp to exactly 1x (no transform
        // at all), not zoom out past the original size.
        await client.send('Input.dispatchTouchEvent', {
            type: 'touchStart',
            touchPoints: [{ x: center.x - 160, y: center.y, id: 0 }, { x: center.x + 160, y: center.y, id: 1 }],
        });
        await client.send('Input.dispatchTouchEvent', {
            type: 'touchMove',
            touchPoints: [{ x: center.x - 5, y: center.y, id: 0 }, { x: center.x + 5, y: center.y, id: 1 }],
        });
        await new Promise((r) => setTimeout(r, 100));
        await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
        const afterPinchIn = await currentTransform();
        assert(afterPinchIn === '', `expected pinching back below 1x to clamp to no transform, got "${afterPinchIn}"`);

        // Double-tap toggles a clean 2x zoom.
        async function tap(x, y) {
            await client.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y, id: 0 }] });
            await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
        }
        await tap(center.x, center.y);
        await new Promise((r) => setTimeout(r, 100));
        await tap(center.x, center.y);
        const afterDoubleTap = await currentTransform();
        assert(afterDoubleTap.includes('scale(2)'), `expected double-tap to zoom to exactly 2x, got "${afterDoubleTap}"`);

        // A single-finger drag while already zoomed in pans the image
        // rather than navigating Next/Previous.
        await client.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: center.x, y: center.y, id: 0 }] });
        await client.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: center.x + 60, y: center.y, id: 0 }] });
        await new Promise((r) => setTimeout(r, 100));
        await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
        const afterPan = await currentTransform();
        assert(!afterPan.startsWith('translate(0px'), `expected the drag to pan the image while zoomed in, got "${afterPan}"`);

        // Double-tap again toggles back to 1x and clears the pan.
        await tap(center.x, center.y);
        await new Promise((r) => setTimeout(r, 100));
        await tap(center.x, center.y);
        const afterSecondDoubleTap = await currentTransform();
        assert(afterSecondDoubleTap === '', `expected a second double-tap to zoom back out to 1x (clearing any pan too), got "${afterSecondDoubleTap}"`);

        noErrors(alice, 'media viewer pinch/double-tap zoom');
        await closeSession(alice);
    } finally {
        fs.rmSync(zoomFixture, { force: true });
    }
});

test('attachment picker: tapping the paperclip opens a WhatsApp-style Camera/Gallery/Document menu, and each option opens its own picker', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    const menuHiddenInitially = await alice.page.evaluate(() => document.getElementById('attachment-menu').classList.contains('hidden'));
    assert(menuHiddenInitially, 'attachment menu should be hidden until the paperclip is tapped');

    await alice.page.click('#attachment-button');
    const menuOptions = await alice.page.evaluate(() => {
        const menu = document.getElementById('attachment-menu');
        return {
            visible: !menu.classList.contains('hidden'),
            text: menu.textContent,
        };
    });
    assert(menuOptions.visible, 'expected the attachment menu to open after tapping the paperclip');
    assert(menuOptions.text.includes('Camera'), 'expected a Camera option in the attachment menu');
    assert(menuOptions.text.includes('Gallery'), 'expected a Gallery option in the attachment menu');
    assert(menuOptions.text.includes('Document'), 'expected a Document option in the attachment menu');

    // Clicking Camera should open the in-app camera modal, not a native
    // OS camera picker - no <input capture> involved anywhere.
    await alice.page.click('#attachment-menu-camera');
    await new Promise((r) => setTimeout(r, 500));
    const cameraOpen = await alice.page.evaluate(() => {
        const modal = document.getElementById('camera-modal');
        return !modal.classList.contains('hidden') && document.getElementById('camera-video').srcObject !== null;
    });
    assert(cameraOpen, 'expected tapping Camera to open the in-app camera modal with a live video stream, not a native file picker');

    await alice.page.click('#camera-close');
    const cameraClosed = await alice.page.evaluate(() => document.getElementById('camera-modal').classList.contains('hidden'));
    assert(cameraClosed, 'expected the close button to dismiss the camera modal');

    // Tapping outside the menu closes it without picking anything.
    await alice.page.click('#attachment-button');
    await alice.page.click('#messages');
    const menuClosedAfterOutsideClick = await alice.page.evaluate(() => document.getElementById('attachment-menu').classList.contains('hidden'));
    assert(menuClosedAfterOutsideClick, 'expected clicking outside the attachment menu to close it');

    noErrors(alice, 'attachment picker menu');
    await closeSession(alice);
});

test('camera: tapping the shutter captures a photo, shows a review screen, and "Use" sends it like a normal attachment', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    await alice.page.click('#attachment-button');
    await alice.page.click('#attachment-menu-camera');
    await new Promise((r) => setTimeout(r, 800));

    // Tap (quick press+release) the shutter - a real photo capture from
    // Chrome's fake camera device (--use-fake-device-for-media-stream),
    // not a mock.
    await alice.page.mouse.move(0, 0);
    const shutterBox = await alice.page.evaluate(() => {
        const rect = document.getElementById('camera-shutter').getBoundingClientRect();
        return { x: rect.x + rect.width / 2, y: rect.y + rect.height / 2 };
    });
    await alice.page.mouse.move(shutterBox.x, shutterBox.y);
    await alice.page.mouse.down();
    await new Promise((r) => setTimeout(r, 50));
    await alice.page.mouse.up();
    await new Promise((r) => setTimeout(r, 500));

    const reviewState = await alice.page.evaluate(() => ({
        reviewVisible: document.getElementById('camera-review').classList.contains('flex'),
        hasImageSrc: !!document.getElementById('camera-review-img').src && document.getElementById('camera-review-img').src.startsWith('blob:'),
    }));
    assert(reviewState.reviewVisible, 'expected a review screen to appear after tapping the shutter');
    assert(reviewState.hasImageSrc, 'expected the review screen to show the actually-captured photo');

    await alice.page.click('#camera-use');
    await new Promise((r) => setTimeout(r, 500));

    const attachedAsPreview = await alice.page.evaluate(() => !document.getElementById('attachment-preview').classList.contains('hidden'));
    assert(attachedAsPreview, 'expected "Use" to feed the captured photo into the normal attachment preview, not send it directly');

    await alice.page.click('#composer button[type="submit"]');
    await new Promise((r) => setTimeout(r, 1500));

    const sent = await alice.page.evaluate(() => document.querySelectorAll('#messages img').length > 0);
    assert(sent, 'expected the captured photo to send like any other image attachment');

    noErrors(alice, 'camera photo capture');
    await closeSession(alice);
});

test('camera: pressing and holding the shutter records a real video instead of taking a photo', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    await alice.page.click('#attachment-button');
    await alice.page.click('#attachment-menu-camera');
    await new Promise((r) => setTimeout(r, 800));

    const shutterBox = await alice.page.evaluate(() => {
        const rect = document.getElementById('camera-shutter').getBoundingClientRect();
        return { x: rect.x + rect.width / 2, y: rect.y + rect.height / 2 };
    });
    await alice.page.mouse.move(shutterBox.x, shutterBox.y);
    await alice.page.mouse.down();

    // Held well past the tap-vs-hold threshold - should switch into
    // recording, not fire a photo capture.
    await new Promise((r) => setTimeout(r, 700));
    const recordingIndicatorVisible = await alice.page.evaluate(() => document.getElementById('camera-record-indicator').classList.contains('flex'));
    assert(recordingIndicatorVisible, 'expected the recording indicator to appear once the shutter is held past the tap threshold');

    await new Promise((r) => setTimeout(r, 800));
    await alice.page.mouse.up();
    await new Promise((r) => setTimeout(r, 800));

    const reviewState = await alice.page.evaluate(() => ({
        reviewVisible: document.getElementById('camera-review').classList.contains('flex'),
        hasVideoSrc: !!document.getElementById('camera-review-video').src && document.getElementById('camera-review-video').src.startsWith('blob:'),
        indicatorGone: !document.getElementById('camera-record-indicator').classList.contains('flex'),
    }));
    assert(reviewState.reviewVisible, 'expected a review screen to appear after releasing the shutter');
    assert(reviewState.hasVideoSrc, 'expected the review screen to show the actually-recorded video, not a photo');
    assert(reviewState.indicatorGone, 'expected the recording indicator to disappear once recording stops');

    await alice.page.click('#camera-use');
    await new Promise((r) => setTimeout(r, 500));
    await alice.page.click('#composer button[type="submit"]');
    await new Promise((r) => setTimeout(r, 2000));

    const sentAsVideo = await alice.page.evaluate(() => document.querySelectorAll('#messages video').length > 0);
    assert(sentAsVideo, 'expected the recorded video to send like any other video attachment');

    // Fully custom-built playback UI - never the browser's own native
    // <video controls> - see createVideoPlayer() in show.blade.php.
    // Scoped to the LAST (just-sent) video since this conversation is
    // reused across every test in this file.
    const player = await alice.page.evaluate(() => {
        var video = [...document.querySelectorAll('#messages video')].pop();
        var wrap = video.closest('.custom-video-player');
        return {
            hasControlsAttr: video.hasAttribute('controls'),
            hasWrapper: !!wrap,
            hasPlayButton: !!(wrap && wrap.querySelectorAll('button').length > 0),
            hasSeekBar: !!(wrap && wrap.querySelector('input[type="range"]')),
        };
    });
    assert(!player.hasControlsAttr, 'expected the <video> element to have NO native controls attribute');
    assert(player.hasWrapper, 'expected the video to be wrapped in the custom player container');
    assert(player.hasPlayButton, 'expected custom play/pause button(s)');
    assert(player.hasSeekBar, 'expected a custom seek bar');

    // Clicking the big center play overlay actually plays the real
    // recorded video.
    await alice.page.evaluate(() => [...document.querySelectorAll('#messages video')].pop().closest('.custom-video-player').querySelector('button').click());
    await new Promise((r) => setTimeout(r, 300));
    const playingState = await alice.page.evaluate(() => ![...document.querySelectorAll('#messages video')].pop().paused);
    assert(playingState, 'expected clicking the custom play overlay to actually start real video playback');

    noErrors(alice, 'camera video recording');
    await closeSession(alice);
});

test('camera: sliding the shutter up while recording locks it hands-free - releasing no longer stops it, only the Stop button does', async () => {
    const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
    const alice = await loginAs(USERS.alice);
    await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

    await alice.page.click('#attachment-button');
    await alice.page.click('#attachment-menu-camera');
    await new Promise((r) => setTimeout(r, 800));

    const shutterBox = await alice.page.evaluate(() => {
        const rect = document.getElementById('camera-shutter').getBoundingClientRect();
        return { x: rect.x + rect.width / 2, y: rect.y + rect.height / 2 };
    });
    await alice.page.mouse.move(shutterBox.x, shutterBox.y);
    await alice.page.mouse.down();
    await new Promise((r) => setTimeout(r, 700)); // past the tap-vs-hold threshold, now recording

    const lockHintVisible = await alice.page.evaluate(() => document.getElementById('camera-lock-hint').classList.contains('flex'));
    assert(lockHintVisible, 'expected the "Slide up to lock" hint to show once recording starts');

    // Drag well past CAMERA_LOCK_SWIPE_PX while still holding down.
    await alice.page.mouse.move(shutterBox.x, shutterBox.y - 100, { steps: 5 });
    await new Promise((r) => setTimeout(r, 100));

    const lockedState = await alice.page.evaluate(() => ({
        stopBtnVisible: document.getElementById('camera-stop-recording').classList.contains('flex'),
        shutterHidden: document.getElementById('camera-shutter').classList.contains('hidden'),
        lockHintGone: document.getElementById('camera-lock-hint').classList.contains('hidden'),
    }));
    assert(lockedState.stopBtnVisible, 'expected the Stop button to appear once locked');
    assert(lockedState.shutterHidden, 'expected the shutter itself to hide once locked (Stop takes over)');
    assert(lockedState.lockHintGone, 'expected the lock hint to disappear once actually locked');

    // Release the mouse/finger entirely - a locked recording must keep going.
    await alice.page.mouse.up();
    await new Promise((r) => setTimeout(r, 600));
    const stillRecording = await alice.page.evaluate(() => document.getElementById('camera-record-indicator').classList.contains('flex'));
    assert(stillRecording, 'expected recording to continue after releasing, since it was locked hands-free');

    // Only the Stop button actually ends it.
    await alice.page.click('#camera-stop-recording');
    await new Promise((r) => setTimeout(r, 500));
    const stopped = await alice.page.evaluate(() => ({
        reviewVisible: document.getElementById('camera-review').classList.contains('flex'),
        hasVideoSrc: !!document.getElementById('camera-review-video').src && document.getElementById('camera-review-video').src.startsWith('blob:'),
    }));
    assert(stopped.reviewVisible, 'expected the review screen after tapping Stop');
    assert(stopped.hasVideoSrc, 'expected a real recorded video after a locked, hands-free recording');

    noErrors(alice, 'camera swipe-to-lock');
    await closeSession(alice);
});

test('media: a large gallery photo is compressed client-side before upload, instead of sent at full camera resolution', async () => {
    // Real bug: a gallery-picked image went into the upload request
    // completely untouched - often several MB at full camera
    // resolution - which is most of what "photo upload is ~2s late"
    // actually was, on a typical mobile upload link. Generated at
    // runtime into the OS temp dir (via PHP GD, already a project
    // dependency) rather than checked in - a real photo-sized fixture
    // (~12MB) doesn't belong permanently bloating the repo. Random line
    // art gives it real noise/entropy, so a trivial/blank image's own
    // compressibility can't be mistaken for the client-side
    // resize+re-encode actually doing the work.
    const LARGE_IMAGE_FIXTURE = path.join(os.tmpdir(), 'meet-test-large-photo-' + Date.now() + '.jpg');
    execSync(`php8.4 -r '
        $im = imagecreatetruecolor(3000, 2000);
        for ($i = 0; $i < 4000; $i++) {
            $color = imagecolorallocate($im, rand(0,255), rand(0,255), rand(0,255));
            imageline($im, rand(0,3000), rand(0,2000), rand(0,3000), rand(0,2000), $color);
        }
        imagejpeg($im, ${JSON.stringify(LARGE_IMAGE_FIXTURE)}, 95);
        imagedestroy($im);
    '`);
    const originalSize = fs.statSync(LARGE_IMAGE_FIXTURE).size;
    try {
        assert(originalSize > 5 * 1024 * 1024, `expected the generated fixture to genuinely be a large photo, got ${originalSize} bytes`);

        const conversationUrl = await ensureConversationUrl(USERS.alice, USERS.bob);
        const alice = await loginAs(USERS.alice);
        await alice.page.goto(conversationUrl, { waitUntil: 'networkidle0' });

        let uploadContentLength = null;
        alice.page.on('request', (req) => {
            if (req.method() === 'POST' && req.url().includes('/api/v1/media')) {
                uploadContentLength = Number(req.headers()['content-length'] || 0);
            }
        });

        const fileInput = await alice.page.$('#attachment-input-gallery');
        await fileInput.uploadFile(LARGE_IMAGE_FIXTURE);
        // Client-side resize+re-encode of a 3000x2000 canvas - fast, but
        // real work, not instant; give it real margin rather than a
        // just-fits guess.
        await new Promise((r) => setTimeout(r, 2500));

        await alice.page.click('#composer button[type="submit"]');
        await new Promise((r) => setTimeout(r, 1500));

        assert(uploadContentLength !== null, 'expected the attachment to actually reach the /api/v1/media upload request');
        assert(uploadContentLength < originalSize * 0.5, `expected the uploaded payload to be meaningfully smaller than the original ${originalSize} bytes, got ${uploadContentLength} (content-length includes some multipart overhead beyond the file itself)`);

        const hasImg = await alice.page.evaluate(() => document.querySelectorAll('#messages img').length > 0);
        assert(hasImg, 'expected the compressed image to still render normally once sent');

        noErrors(alice, 'large photo compression');
        await closeSession(alice);
    } finally {
        fs.rmSync(LARGE_IMAGE_FIXTURE, { force: true });
    }
});
