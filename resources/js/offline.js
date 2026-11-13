/**
 * Offline-first data cache + outbox queue. This is a deliberate reversal
 * of this project's original FR-259 scope (which explicitly excluded
 * "Chat Synchronization" from offline support, keeping only the login
 * screen/shell cacheable) - see Documentation's consolidated notes for
 * why. Calls, meetings, and file uploads remain genuinely impossible
 * offline (they need a live WebRTC/network connection by nature) and
 * are not touched by any of this.
 *
 * Everything here is plain IndexedDB, mirroring e2e-crypto.js's own
 * pattern - one object store per cached collection, plus an `outbox`
 * store for actions taken while offline, replayed in order once back
 * online.
 */
(function (global) {
    'use strict';

    const DB_NAME = 'meet-offline';
    const DB_VERSION = 1;
    const STORES = ['conversations', 'messages', 'contacts', 'outbox'];

    function openDb() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(DB_NAME, DB_VERSION);
            request.onupgradeneeded = () => {
                const db = request.result;
                if (!db.objectStoreNames.contains('conversations')) db.createObjectStore('conversations');
                if (!db.objectStoreNames.contains('messages')) db.createObjectStore('messages');
                if (!db.objectStoreNames.contains('contacts')) db.createObjectStore('contacts');
                if (!db.objectStoreNames.contains('outbox')) db.createObjectStore('outbox', { keyPath: 'localId' });
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    function put(store, key, value) {
        return openDb().then((db) => new Promise((resolve, reject) => {
            const tx = db.transaction(store, 'readwrite');
            tx.objectStore(store).put(value, key);
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        }));
    }

    function get(store, key) {
        return openDb().then((db) => new Promise((resolve, reject) => {
            const tx = db.transaction(store, 'readonly');
            const req = tx.objectStore(store).get(key);
            req.onsuccess = () => resolve(req.result === undefined ? null : req.result);
            req.onerror = () => reject(req.error);
        }));
    }

    function cacheConversations(list) {
        return put('conversations', 'list', { list, cachedAt: Date.now() });
    }

    function cachedConversations() {
        return get('conversations', 'list').then((entry) => entry ? entry.list : null);
    }

    function cacheMessages(conversationId, messages) {
        // Capped so a very long thread doesn't grow IndexedDB usage
        // unbounded - offline reading gets the most recent 200 messages,
        // not the entire history. Flagged, not silent: older messages
        // simply aren't available offline until the network is back.
        return put('messages', String(conversationId), { messages: messages.slice(-200), cachedAt: Date.now() });
    }

    function cachedMessages(conversationId) {
        return get('messages', String(conversationId)).then((entry) => entry ? entry.messages : null);
    }

    function cacheContacts(list) {
        return put('contacts', 'list', { list, cachedAt: Date.now() });
    }

    function cachedContacts() {
        return get('contacts', 'list').then((entry) => entry ? entry.list : null);
    }

    // --- Outbox: actions taken while offline, replayed on reconnect ---

    function enqueue(action) {
        const localId = 'outbox-' + Date.now() + '-' + Math.random().toString(36).slice(2);
        return openDb().then((db) => new Promise((resolve, reject) => {
            const tx = db.transaction('outbox', 'readwrite');
            tx.objectStore('outbox').put({ localId, ...action, queuedAt: Date.now() });
            tx.oncomplete = () => resolve(localId);
            tx.onerror = () => reject(tx.error);
        }));
    }

    function listQueued() {
        return openDb().then((db) => new Promise((resolve, reject) => {
            const tx = db.transaction('outbox', 'readonly');
            const req = tx.objectStore('outbox').getAll();
            req.onsuccess = () => resolve(req.result.sort((a, b) => a.queuedAt - b.queuedAt));
            req.onerror = () => reject(req.error);
        }));
    }

    function dequeue(localId) {
        return openDb().then((db) => new Promise((resolve, reject) => {
            const tx = db.transaction('outbox', 'readwrite');
            tx.objectStore('outbox').delete(localId);
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        }));
    }

    /**
     * Replays every queued action in the order it was queued, one at a
     * time (not in parallel - a queued edit/delete must apply after its
     * own send, and later actions on the same message would race a
     * parallel replay). `handlers` maps an action's `type` to a
     * function(action) => Promise that actually performs it - supplied
     * by the caller (show.blade.php) since only it knows how to
     * re-encrypt/re-send a given action type. A handler that rejects
     * with `{permanent: true}` drops the action (e.g. the message it
     * targeted no longer exists); anything else leaves it queued to
     * retry on the next reconnect.
     */
    function processQueue(handlers) {
        return listQueued().then((actions) => {
            return actions.reduce((chain, action) => chain.then(() => {
                const handler = handlers[action.type];
                if (!handler) return dequeue(action.localId);

                return handler(action)
                    .then(() => dequeue(action.localId))
                    .catch((error) => {
                        if (error && error.permanent) {
                            return dequeue(action.localId);
                        }
                        // Leave it queued - stop processing further
                        // actions so ordering isn't violated by skipping
                        // ahead of a still-stuck one.
                        return Promise.reject(error);
                    });
            }), Promise.resolve()).catch((error) => {
                console.error('Offline queue processing stopped early', error);
            });
        });
    }

    function queueLength() {
        return listQueued().then((actions) => actions.length);
    }

    global.MeetOffline = {
        cacheConversations,
        cachedConversations,
        cacheMessages,
        cachedMessages,
        cacheContacts,
        cachedContacts,
        enqueue,
        listQueued,
        dequeue,
        processQueue,
        queueLength,
    };
})(window);
