<?php

namespace Modules\Core\Services;

use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Modules\Authentication\Models\User;
use Modules\Core\Models\PushSubscription;

/**
 * Real OS-level push - reaches a subscribed device even with the
 * tab/app fully closed, unlike the foreground-only Notification
 * already wired up in the shared layout. Called synchronously inline
 * (this app's established no-queue-worker convention, matching every
 * other cleanup/notification job here), from three trigger points:
 * new message, incoming call, contact request.
 *
 * Goes entirely through Firebase Cloud Messaging, per direct
 * instruction - resources/js/push-notifications.js uses Firebase's Web
 * SDK to obtain a device token (still the same underlying browser
 * Push API/service worker under the hood, so
 * public/service-worker.js's push/notificationclick handlers are
 * untouched), and this service uses Firebase's Admin SDK to send.
 * Sent as a data-only message (no FCM "notification" payload) so the
 * service worker's own push handler stays in full control of exactly
 * what's shown - matching what it already did for raw Web Push.
 */
class PushNotificationService
{
    public function subscribe(User $user, string $fcmToken, ?string $userAgent = null): PushSubscription
    {
        return PushSubscription::updateOrCreate(
            ['user_id' => $user->id, 'fcm_token' => $fcmToken],
            ['user_agent' => $userAgent ? mb_substr($userAgent, 0, 150) : null],
        );
    }

    public function unsubscribe(User $user, string $fcmToken): void
    {
        PushSubscription::where('user_id', $user->id)->where('fcm_token', $fcmToken)->delete();
    }

    /**
     * All of a user's notify_* preferences going false means they
     * don't want anything pushed to them anymore - one subscription
     * row serves every trigger type indiscriminately (there's no
     * per-type subscription), so partial unsubscribing per-toggle
     * isn't meaningful; this only ever fires when every toggle is off
     * at once (see UserProfileService::updatePreferences()).
     */
    public function unsubscribeAll(User $user): void
    {
        PushSubscription::where('user_id', $user->id)->delete();
    }

    /**
     * Silently no-ops (no exception, no partial send) when Firebase
     * credentials aren't configured - a fresh install without them
     * shouldn't have every message/call/contact-request action start
     * failing.
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        if (! config('firebase.project_id') || ! config('firebase.private_key')) {
            return;
        }

        $subscriptions = PushSubscription::where('user_id', $user->id)->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        // A push failure of any kind (unreachable Firebase, expired/
        // invalid token, malformed credentials) must never break the
        // real action that triggered it (sending a message, starting a
        // call, sending a contact request) - this whole block is a
        // best-effort side notification.
        try {
            $messaging = (new Factory)->withServiceAccount([
                'type' => 'service_account',
                'project_id' => config('firebase.project_id'),
                'client_email' => config('firebase.client_email'),
                'private_key' => config('firebase.private_key'),
            ])->createMessaging();

            // FCM data-message values must all be strings; null entries
            // (e.g. no call_id on a message notification) are dropped
            // rather than sent as the literal string "".
            $payload = array_map(
                static fn ($value) => (string) $value,
                array_filter(array_merge(['title' => $title, 'body' => $body], $data), static fn ($v) => $v !== null),
            );

            foreach ($subscriptions as $subscription) {
                $message = CloudMessage::new()->withToken($subscription->fcm_token)->withData($payload);

                try {
                    $messaging->send($message);
                } catch (NotFound) {
                    // The token is no longer registered (app uninstalled,
                    // browser data cleared, etc.) - stop trying it.
                    $subscription->delete();
                }
            }
        } catch (\Throwable) {
            // Swallowed deliberately - see comment above.
        }
    }
}
