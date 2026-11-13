// SRS/16.md FR-258 - Service Worker responsibilities: cache static
// assets, cache the app shell, offline fallback for the login screen.
//
// FR-259 offline scope is deliberately narrow: Login Screen,
// Navigation Shell, cached theme/icons/static assets. Chat sync,
// calls, meetings, authentication, and file uploads explicitly
// require the network per FR-259 and are never served from cache -
// this worker only intercepts requests for static assets and the
// login page itself.
//
// FR-258 also requires that "updates shall not interrupt active
// meetings or calls" - achieved simply by never calling
// self.skipWaiting() here, so a new worker version waits in the
// background until the user's next full page load rather than
// taking over mid-session.

// Bumped to v2 so installed PWA users' cache-first /icons/* entries
// (see isStaticAsset() below) actually pick up the new app icon
// instead of serving the old cached bytes forever under the same URL.
const CACHE_NAME = 'meet-shell-v2';
const PRECACHE_URLS = [
    '/login',
    '/manifest.json',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/favicon-32.png',
];

// Lets the page trigger activation of an already-installed, still-
// waiting worker once it's confirmed it's safe to do so (see
// pwa-head.blade.php) - this is the only way to shorten the wait
// described in the file header comment above without ever calling
// self.skipWaiting() unconditionally.
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE_URLS).catch(() => {})),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)),
        )),
    );
});

function isStaticAsset(url) {
    return url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/');
}

// A deliberate reversal of FR-259's original "Chat Synchronization
// requires an active network connection" limitation - see
// Documentation/IMPLEMENTATION_CHANGES.md. Calls and meetings are
// excluded on purpose: they're real-time WebRTC sessions with no
// offline behavior even in principle, so /calls and /meetings pages
// are never runtime-cached here, matching FR-258/FR-259's still-valid
// constraint for those two specifically.
function isOfflineCapableShellPage(url) {
    return url.pathname === '/chat' || url.pathname.startsWith('/chat/')
        || url.pathname === '/contacts' || url.pathname.startsWith('/contacts/')
        || url.pathname === '/groups' || url.pathname.startsWith('/groups/');
}

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    if (event.request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    // Static assets: cache-first (they're content-hashed by Vite, so a
    // cached copy is always the correct one for its URL).
    if (isStaticAsset(url)) {
        event.respondWith(
            caches.match(event.request).then((cached) => cached || fetch(event.request).then((response) => {
                const clone = response.clone();
                caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
                return response;
            })),
        );
        return;
    }

    // The login page, and now the chat/contacts/groups shells too:
    // network-first, falling back to whatever was cached from this
    // user's last successful visit when offline. The page's own JS then
    // repopulates real conversation/message/contact data from
    // IndexedDB (see offline.js) - this only gets the HTML shell itself
    // loadable without a network round-trip.
    if (url.pathname === '/login' || isOfflineCapableShellPage(url)) {
        event.respondWith(
            fetch(event.request)
                .then((response) => {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
                    return response;
                })
                .catch(() => caches.match(event.request).then((cached) => cached || caches.match('/login'))),
        );
        return;
    }

    // Everything else (calls, meetings, auth, uploads) is intentionally
    // left to the network - no offline behavior for those, matching
    // FR-258/FR-259's still-valid constraint for real-time WebRTC
    // sessions and anything needing a live network round-trip by
    // nature.
});

// Real OS-level Web Push, sent via Firebase Cloud Messaging (Admin SDK,
// see PushNotificationService) - a flat, all-string data-only message:
// { title, body, type, conversation_id?, call_id? }. FCM's exact web
// wire envelope can arrive either as that flat map directly, or nested
// under a `data` key (observed to vary by FCM client library/version) -
// this reads whichever shape is actually present instead of assuming
// one, since there's no way to verify the exact envelope without a
// real device receiving a real push through Google's infrastructure.
self.addEventListener('push', (event) => {
    if (!event.data) {
        return;
    }

    let raw;
    try {
        raw = event.data.json();
    } catch (e) {
        return;
    }

    const payload = raw.data || raw;
    const data = {
        type: payload.type,
        conversation_id: payload.conversation_id,
        call_id: payload.call_id,
        caller_id: payload.caller_id,
        call_type: payload.call_type,
        caller_name: payload.title,
    };
    const options = {
        body: payload.body || '',
        // Per direct instruction: the monochrome mark, not the full-color
        // app icon, for the notification itself too - icon-monochrome-192.png
        // is the same white-silhouette-on-transparent technique as
        // badge-96.png (both generated from icon-192.png via a one-off GD
        // script, not committed source art), just at the larger size the
        // main notification icon actually renders at.
        icon: '/icons/icon-monochrome-192.png',
        // Android's notification badge (the small status-bar icon) is
        // rendered as a monochrome silhouette regardless of what's fed
        // to it - passing the full-color app icon here just produces
        // an indistinct white blob in the status bar, which reads as
        // "no app icon shown." badge-96.png is that same silhouette at
        // the smaller size that spot actually uses.
        badge: '/icons/badge-96.png',
        data,
    };

    // A quick-reply action, right in the notification - only offered
    // for actual chat messages (replying to a still-ringing/contact-
    // request notification makes no sense, but a MISSED call's own
    // conversation is a perfectly normal thing to reply into - "can't
    // talk, what's up?"). `type: 'text'` is Chrome for Android's real
    // inline-reply UI; every other browser just ignores the
    // unsupported `type` and shows it as a plain action button instead
    // (falls back to the usual "open the conversation" behavior on
    // click, see notificationclick below).
    if (data.type === 'message') {
        options.actions = [
            { action: 'reply', title: 'Reply', type: 'text', placeholder: 'Type a message…' },
            { action: 'mark_read', title: 'Mark as read' },
        ];
    } else if (data.type === 'missed_call') {
        options.actions = [
            { action: 'call_back', title: 'Call back' },
        ];
        if (data.conversation_id) {
            options.actions.push({ action: 'reply', title: 'Message', type: 'text', placeholder: 'Type a message…' });
        }
    } else if (data.type === 'call' && data.call_id) {
        // A RINGING call. This used to show the caller's name and
        // "Calling…" with nothing whatsoever to act on - you had to find
        // and open the app before you could answer, by which time the
        // 30s ring window was often gone. Accept/Decline mirror exactly
        // how the missed-call notification's own Call back/Message
        // actions work (see notificationclick below), the difference
        // being that Decline completes entirely inside the worker while
        // Accept has to hand off to a page for the media session.
        options.actions = [
            { action: 'accept_call', title: 'Accept' },
            { action: 'decline_call', title: 'Decline' },
        ];
        // requireInteraction keeps a ringing call on screen instead of
        // auto-dismissing after a few seconds like a chat message
        // should; renotify re-alerts (sound/vibration) even though the
        // 'call-N' tag below replaces the previous notification for the
        // same call; and vibrate is the one physical alert a service
        // worker genuinely CAN produce with no page open (see the
        // honest note on ringtones in app.blade.php's playRingtone -
        // audio playback from a worker is not possible on any major
        // platform, so the OS's own notification sound plus this
        // vibration pattern is what "the device rings" really means
        // before a page is focused).
        options.requireInteraction = true;
        options.renotify = true;
        options.silent = false;
        // Real fix: this used to be a single ~1.75s buzz - "the phone
        // barely twitches once", nothing like an actual incoming call.
        // A single vibrate pattern is still finite (no browser lets a
        // notification vibrate forever), but it can be a real ring
        // cadence lasting close to the actual ring window instead of a
        // token blip - ~1.2s buzz / 0.8s pause, repeated for ~30s to
        // roughly match ring_timeout_seconds's own default.
        options.vibrate = new Array(15).fill([1200, 800]).flat();
    }

    // Real bug fix: every push used to show as its OWN separate
    // notification, so texting someone twice in a row stacked two
    // notifications instead of updating one - a `tag` scoped to the
    // conversation (or the call) makes the next push for the same
    // conversation REPLACE the previous one rather than stack, which
    // is also what lets the accumulation below actually work (there's
    // only ever one live notification per conversation to update).
    if (data.conversation_id) {
        options.tag = 'conv-' + data.conversation_id;
    } else if (data.call_id) {
        options.tag = 'call-' + data.call_id;
    }

    // Building the actual notification body/title (with accumulation
    // for repeated messages from the same conversation while the
    // previous notification is still showing) needs to happen before
    // showNotification, but reading "is there already one showing"
    // requires an async call - wrap the rest in a function so it can
    // be awaited inside event.waitUntil() below instead of blocking
    // the synchronous top of the handler.
    function buildAndShow() {
        if (!options.tag) {
            return self.registration.showNotification(payload.title || 'Meet', options);
        }

        return self.registration.getNotifications({ tag: options.tag }).then((existing) => {
            const previous = existing[0];
            const previousCount = (previous && previous.data && previous.data.messageCount) || 0;
            const count = previousCount + 1;
            data.messageCount = count;

            let title = payload.title || 'Meet';
            let body = payload.body || '';
            if (count > 1 && data.type === 'message') {
                title = title + ' (' + count + ' new messages)';
                // Keep showing the latest message's actual text - a
                // bare "N new messages" with no content is worse than
                // what a real chat app's grouped notification shows.
            }
            options.body = body;
            options.data = data;

            return self.registration.showNotification(title, options);
        });
    }

    // A push notification reaching this device while the app is fully
    // backgrounded/closed IS real evidence the message got here -
    // without this, "delivered" (the second tick) only ever advanced
    // from the recipient's own client polling GET /conversations or
    // GET /messages/{conversation}, so a message pushed to a closed
    // app just sat on "sent" forever even though it had genuinely
    // arrived. Fire-and-forget alongside showNotification, not
    // sequenced before/after it - neither should block the other.
    const tasks = [buildAndShow()];
    if (data.type === 'message' && data.conversation_id) {
        tasks.push(markMessageDelivered(data.conversation_id));
    }

    event.waitUntil(Promise.all(tasks));
});

function bufToBase64(buf) {
    const bytes = new Uint8Array(buf);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
    return btoa(binary);
}

function base64ToBuf(b64) {
    const binary = atob(b64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    return bytes.buffer;
}

// Reads the XSRF-TOKEN cookie the same way axios's withXSRFToken option
// does on every normal page request (see resources/js/bootstrap.js) -
// a service worker has no `document.cookie`, so the Chromium-only
// cookieStore API is used instead. Fine for this feature specifically:
// the inline-reply notification action this token is needed for is
// itself Chrome-for-Android-only, so this isn't a narrower constraint
// than what already applies.
async function getXsrfToken() {
    if (!('cookieStore' in self)) return null;
    const cookie = await self.cookieStore.get('XSRF-TOKEN');
    return cookie ? decodeURIComponent(cookie.value) : null;
}

/**
 * Fire-and-forget delivery marking for a message push received while
 * the app is backgrounded/closed - see the push handler above and
 * MessageService::markDelivered()'s docblock. Same session-cookie auth
 * as every other request (credentials: 'include'), not gated on
 * cookieStore support the way the reply action is - a missing XSRF
 * token here just means the request 419s and delivery marking is
 * skipped for this one push, not a broken feature.
 */
async function markMessageDelivered(conversationId) {
    const xsrfToken = await getXsrfToken();
    const headers = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (xsrfToken) headers['X-XSRF-TOKEN'] = xsrfToken;

    return fetch('/api/v1/conversations/' + conversationId + '/delivered', {
        method: 'POST',
        credentials: 'include',
        headers,
    }).catch(() => {});
}

/**
 * "Mark as read" straight from the notification, with no page open -
 * same session-cookie + XSRF-token auth as every other service-worker
 * request here.
 */
async function markConversationRead(conversationId) {
    const xsrfToken = await getXsrfToken();
    const headers = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (xsrfToken) headers['X-XSRF-TOKEN'] = xsrfToken;

    return fetch('/api/v1/conversations/' + conversationId + '/read', {
        method: 'POST',
        credentials: 'include',
        headers,
    }).catch(() => {});
}

/**
 * Sends a quick reply typed directly into the notification shade, with
 * no page/tab open at all - encrypts it exactly like a normal in-app
 * send would (fetching the conversation's current key epoch, same
 * endpoints e2e-crypto.js uses), since messages are end-to-end
 * encrypted automatically now. A plain new message in that
 * conversation, not a quote-reply to any specific one - matching how
 * a real quick-reply works elsewhere.
 */
async function sendQuickReply(conversationId, text) {
    const xsrfToken = await getXsrfToken();
    const headers = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (xsrfToken) headers['X-XSRF-TOKEN'] = xsrfToken;

    const statusResponse = await fetch('/api/v1/conversations/' + conversationId + '/encryption', { credentials: 'include', headers });
    const status = (await statusResponse.json()).data;

    let body = text;
    let encryptionFields = {};

    if (status && status.eligible && status.current_key_version) {
        const keyResponse = await fetch('/api/v1/conversations/' + conversationId + '/encryption/keys/' + status.current_key_version, { credentials: 'include', headers });
        const keyData = (await keyResponse.json()).data;

        if (keyData && keyData.key_material) {
            const aesKey = await crypto.subtle.importKey('raw', base64ToBuf(keyData.key_material), { name: 'AES-GCM', length: 256 }, false, ['encrypt']);
            const iv = crypto.getRandomValues(new Uint8Array(12));
            const ciphertext = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, aesKey, new TextEncoder().encode(text));
            body = bufToBase64(ciphertext);
            encryptionFields = { encryption_mode: 'e2e', iv: bufToBase64(iv), key_version: status.current_key_version };
        }
    }

    return fetch('/api/v1/messages', {
        method: 'POST',
        credentials: 'include',
        headers,
        body: JSON.stringify(Object.assign({ conversation_id: conversationId, body }, encryptionFields)),
    });
}

/**
 * Accept or decline a ringing call straight from the notification, with
 * no page open. Same session-cookie + XSRF-token auth as every other
 * service-worker request here, hitting the exact endpoints the in-page
 * Accept/Decline buttons use.
 *
 * Declining is genuinely complete in the worker: the caller stops
 * ringing without this device ever opening the app. Accepting can only
 * get halfway here - it tells the server "answered" immediately (so the
 * caller's UI moves on at once rather than waiting out a page load), but
 * a service worker cannot touch getUserMedia or hold an
 * RTCPeerConnection, so the media session itself is handed to a page via
 * ?answer_call=<id>, which app.blade.php picks up on load and joins.
 */
async function respondToCall(callId, action) {
    const xsrfToken = await getXsrfToken();
    const headers = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (xsrfToken) headers['X-XSRF-TOKEN'] = xsrfToken;

    return fetch('/api/v1/calls/' + callId + '/' + action, {
        method: 'POST',
        credentials: 'include',
        headers,
    });
}

self.addEventListener('notificationclick', (event) => {
    var data = event.notification.data || {};

    // Decline: fully handled here, nothing needs to open.
    if (event.action === 'decline_call' && data.call_id) {
        event.notification.close();
        event.waitUntil(respondToCall(data.call_id, 'reject').catch(function () {}));
        return;
    }

    // Accept: tell the server now, then open/focus a page that can
    // actually join the media session (see the answer_call handling in
    // app.blade.php). The accept POST is deliberately not awaited before
    // opening - a slow round-trip must not delay the window opening, and
    // the page falls back to accepting itself if this request failed.
    if (event.action === 'accept_call' && data.call_id) {
        event.notification.close();
        event.waitUntil(
            Promise.all([
                respondToCall(data.call_id, 'accept').catch(function () {}),
                openOrFocus('/dashboard?answer_call=' + encodeURIComponent(data.call_id)),
            ]),
        );
        return;
    }

    if (event.action === 'reply' && event.reply && (data.type === 'message' || data.type === 'missed_call') && data.conversation_id) {
        event.notification.close();
        event.waitUntil(
            sendQuickReply(data.conversation_id, event.reply).catch(function () {
                // The send failed (network hiccup, session expired,
                // etc.) - open the conversation as a fallback so the
                // reply isn't silently lost, rather than pretending it
                // sent.
                return openOrFocus('/chat/' + data.conversation_id);
            }),
        );
        return;
    }

    // No page is necessarily open at all, so a call can't be started
    // directly from here the way a page-side click handler would -
    // the callback_* query params are picked up by the shared layout's
    // own JS (see app.blade.php) once a client window exists, the same
    // trick meeting-rejoin-on-refresh already uses for "act once the
    // page has actually loaded".
    if (event.action === 'mark_read' && data.type === 'message' && data.conversation_id) {
        event.notification.close();
        event.waitUntil(markConversationRead(data.conversation_id));
        return;
    }

    if (event.action === 'call_back' && data.type === 'missed_call' && data.caller_id) {
        event.notification.close();
        var params = 'callback_user=' + encodeURIComponent(data.caller_id)
            + '&callback_type=' + encodeURIComponent(data.call_type || 'voice')
            + '&callback_name=' + encodeURIComponent(data.caller_name || '');
        event.waitUntil(openOrFocus('/dashboard?' + params));
        return;
    }

    event.notification.close();

    var url = '/chat';
    if (data.type === 'message' && data.conversation_id) {
        url = '/chat/' + data.conversation_id;
    } else if (data.type === 'contact_request') {
        url = '/contacts/requests';
    } else if (data.type === 'call') {
        // Tapping the notification BODY (rather than Accept) must not
        // silently answer for the user - it just opens the app, whose
        // incoming-call poll now runs immediately on load (not only on
        // its first interval tick), so the in-app ringing screen with
        // its own Accept/Decline and ringtone comes straight up.
        url = '/dashboard';
    } else if (data.type === 'missed_call') {
        url = '/calls/history';
    }

    event.waitUntil(openOrFocus(url));
});

function openOrFocus(url) {
    return clients.matchAll({ type: 'window' }).then(function (windowClients) {
        for (var i = 0; i < windowClients.length; i++) {
            if (windowClients[i].url.includes(self.location.origin) && 'focus' in windowClients[i]) {
                windowClients[i].navigate(url);
                return windowClients[i].focus();
            }
        }
        return clients.openWindow(url);
    });
}
