/**
 * Real OS-level push notifications via Firebase Cloud Messaging.
 * Firebase's Web SDK obtains a device token using the *same* browser
 * Push API/service worker this app already registers for everything
 * else (public/service-worker.js) - passed in explicitly via
 * `serviceWorkerRegistration` so Firebase doesn't register its own
 * separate `firebase-messaging-sw.js` (a page can only have one active
 * service worker controlling it). That existing service worker's own
 * `push`/`notificationclick` handlers are untouched by this - Firebase
 * is only used here to obtain/manage the device token, not to
 * intercept push events itself.
 */
import { initializeApp } from 'firebase/app';
import { getMessaging, getToken, deleteToken, onMessage } from 'firebase/messaging';

function readMeta(name) {
    return document.querySelector('meta[name="' + name + '"]')?.content || null;
}

function firebaseConfig() {
    const apiKey = readMeta('firebase-api-key');
    const projectId = readMeta('firebase-project-id');
    if (!apiKey || !projectId) return null;

    return {
        apiKey,
        authDomain: readMeta('firebase-auth-domain'),
        projectId,
        storageBucket: readMeta('firebase-storage-bucket'),
        messagingSenderId: readMeta('firebase-messaging-sender-id'),
        appId: readMeta('firebase-app-id'),
    };
}

let messagingInstance;

function getMessagingInstance() {
    if (messagingInstance !== undefined) return messagingInstance;

    const config = firebaseConfig();
    messagingInstance = config ? getMessaging(initializeApp(config)) : null;
    return messagingInstance;
}

/**
 * onMessage() only ever fires for a foreground/visible page holding
 * this messaging instance - a backgrounded tab still goes through the
 * service worker's own `push` handler exactly as before, untouched by
 * this. Re-broadcast as a plain DOM CustomEvent rather than reaching
 * into any page-specific state directly, so any page's own script
 * (e.g. the open chat thread wanting to poll instantly instead of
 * waiting for its next interval tick) can listen without this module
 * needing to know anything about what's currently on screen.
 */
function registerForegroundMessageListener() {
    const messaging = getMessagingInstance();
    if (!messaging) return;

    onMessage(messaging, (payload) => {
        window.dispatchEvent(new CustomEvent('meet-push-message', { detail: payload.data || {} }));
    });
}

registerForegroundMessageListener();

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; i++) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

function keysMatch(a, b) {
    if (!a || !b || a.length !== b.length) return false;
    for (let i = 0; i < a.length; i++) {
        if (a[i] !== b[i]) return false;
    }
    return true;
}

/**
 * A push subscription from a previous VAPID key (a different Firebase
 * project, or an earlier non-Firebase Web Push setup before this
 * feature existed) makes the browser's own pushManager.subscribe()
 * throw ("a subscription with a different applicationServerKey
 * already exists") the instant Firebase's getToken() tries to create
 * a fresh one under the hood - previously swallowed silently by
 * getDeviceToken()'s bare catch, so a user who'd had ANY push
 * subscription before this feature shipped could never actually get a
 * working one afterwards, with no visible error anywhere. Exported on
 * window.MeetPush so it's testable directly without needing real
 * Firebase credentials configured.
 */
async function unsubscribeIfStale(serviceWorkerRegistration, vapidKey) {
    const subscription = await serviceWorkerRegistration.pushManager.getSubscription();
    if (!subscription) return;

    const currentKey = urlBase64ToUint8Array(vapidKey);
    const subscriptionKey = subscription.options && subscription.options.applicationServerKey
        ? new Uint8Array(subscription.options.applicationServerKey)
        : null;

    if (!keysMatch(currentKey, subscriptionKey)) {
        await subscription.unsubscribe().catch(() => {});
    }
}

/**
 * Requests (or reuses) an FCM device token and returns it, or null if
 * Firebase isn't configured, permission isn't granted, or obtaining a
 * token otherwise fails (no real push service reachable, etc.).
 */
async function getDeviceToken(serviceWorkerRegistration) {
    const messaging = getMessagingInstance();
    const vapidKey = readMeta('firebase-vapid-key');
    if (!messaging || !vapidKey) return null;

    await unsubscribeIfStale(serviceWorkerRegistration, vapidKey);

    try {
        return await getToken(messaging, { vapidKey, serviceWorkerRegistration });
    } catch (error) {
        return null;
    }
}

async function forgetDeviceToken() {
    const messaging = getMessagingInstance();
    if (!messaging) return;

    try {
        await deleteToken(messaging);
    } catch (error) {
        // Best-effort - a token that's already gone (or never existed)
        // isn't a real failure here.
    }
}

window.MeetPush = { getDeviceToken, forgetDeviceToken, unsubscribeIfStale, urlBase64ToUint8Array };
