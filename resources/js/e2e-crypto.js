/**
 * Message encryption (WebCrypto), server-managed keys. Per direct
 * instruction, this is a deliberately simpler trust model than a
 * zero-knowledge scheme: the app itself generates each conversation's
 * AES-256-GCM key and stores it server-side (encrypted at rest with
 * the app's own key, not derivable from anything the user knows) -
 * there's no per-user keypair, no password-derived unlock step, and
 * no "Unlock encrypted messages" prompt. Any authenticated, current
 * member of a conversation can fetch its key on request.
 *
 * The client's only job is to cache the fetched key locally (IndexedDB
 * - persists across reloads on both desktop and mobile browsers/
 * installed PWAs, unlike an in-memory variable) so it doesn't have to
 * re-fetch on every message; a cache miss (first visit on this device,
 * or a cleared/private-mode browser) transparently re-fetches from the
 * server and re-populates the cache.
 */
(function (global) {
    'use strict';

    const DB_NAME = 'meet-e2e';
    const DB_STORE = 'conversation-keys';

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

    function randomBytes(length) {
        return crypto.getRandomValues(new Uint8Array(length));
    }

    function openDb() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(DB_NAME, 1);
            request.onupgradeneeded = () => {
                if (!request.result.objectStoreNames.contains(DB_STORE)) {
                    request.result.createObjectStore(DB_STORE);
                }
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    function cacheKeyName(conversationId, keyVersion) {
        return conversationId + ':' + keyVersion;
    }

    function getCachedKeyMaterial(conversationId, keyVersion) {
        return openDb().then((db) => new Promise((resolve, reject) => {
            const tx = db.transaction(DB_STORE, 'readonly');
            const req = tx.objectStore(DB_STORE).get(cacheKeyName(conversationId, keyVersion));
            req.onsuccess = () => resolve(req.result || null);
            req.onerror = () => reject(req.error);
        }));
    }

    function cacheKeyMaterial(conversationId, keyVersion, materialBase64) {
        return openDb().then((db) => new Promise((resolve, reject) => {
            const tx = db.transaction(DB_STORE, 'readwrite');
            tx.objectStore(DB_STORE).put(materialBase64, cacheKeyName(conversationId, keyVersion));
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        }));
    }

    /**
     * The one entry point every page needs: a ready-to-use AES-GCM key
     * for this conversation's given epoch. Checks the local cache
     * first; on a miss, fetches the raw key material from the server
     * (the request itself is what's authorized - conversation
     * membership - there's nothing else to unlock) and caches it for
     * next time. Returns null if the caller isn't actually an active
     * member, or that epoch was never issued.
     */
    async function getConversationKey(conversationId, keyVersion) {
        let materialBase64 = await getCachedKeyMaterial(conversationId, keyVersion);

        if (!materialBase64) {
            const response = await axios.get('/api/v1/conversations/' + conversationId + '/encryption/keys/' + keyVersion);
            if (!response.data.data) return null;
            materialBase64 = response.data.data.key_material;
            await cacheKeyMaterial(conversationId, keyVersion, materialBase64);
        }

        return crypto.subtle.importKey('raw', base64ToBuf(materialBase64), { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
    }

    async function encryptText(plaintext, aesKey) {
        const iv = randomBytes(12);
        const ciphertext = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, aesKey, new TextEncoder().encode(plaintext));
        return { body: bufToBase64(ciphertext), iv: bufToBase64(iv) };
    }

    async function decryptText(bodyBase64, ivBase64, aesKey) {
        const plaintext = await crypto.subtle.decrypt(
            { name: 'AES-GCM', iv: base64ToBuf(ivBase64) },
            aesKey,
            base64ToBuf(bodyBase64),
        );
        return new TextDecoder().decode(plaintext);
    }

    global.MeetE2E = {
        bufToBase64,
        base64ToBuf,
        getConversationKey,
        encryptText,
        decryptText,
    };
})(window);
