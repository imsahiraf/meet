<link rel="manifest" href="{{ route('pwa.manifest') }}">
<meta name="theme-color" content="{{ $pwaThemeColor ?? '#4f46e5' }}">
<link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
<link rel="icon" href="{{ asset('icons/favicon-32.png') }}" sizes="32x32">
<script>
    // FR-258 - registered on every page (including the guest/login
    // layout) so the offline app shell is available before the user
    // ever authenticates, per FR-259's documented offline scope.
    //
    // An installed PWA that's rarely fully closed (every tab/window for
    // this origin closed at once) can otherwise sit on a new-but-never-
    // activated worker indefinitely, since the browser only activates
    // one on its own once nothing controlled by the old one is left
    // open - this is what left real devices stuck on old app versions
    // even after a real update shipped. Checks on every page load
    // instead, and only ever tells the worker to activate (never
    // self.skipWaiting() unconditionally in the worker itself) once
    // it's confirmed no call/meeting is actively in progress on this
    // page, retrying later otherwise - satisfies FR-258's "updates
    // shall not interrupt active meetings or calls" the same way the
    // original never-skipWaiting design did, just no longer leaving it
    // to chance when the update actually lands.
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/service-worker.js').then(function (registration) {
                function isSafeToUpdateNow() {
                    var callActive = document.getElementById('call-active');
                    var roomPanel = document.getElementById('room-panel');
                    var inCall = callActive && !callActive.classList.contains('hidden');
                    var inMeetingRoom = roomPanel && !roomPanel.classList.contains('hidden');

                    return !inCall && !inMeetingRoom;
                }

                function activateWhenSafe(worker) {
                    (function tryActivate() {
                        if (isSafeToUpdateNow()) {
                            worker.postMessage({ type: 'SKIP_WAITING' });
                        } else {
                            setTimeout(tryActivate, 30000);
                        }
                    })();
                }

                if (registration.waiting) {
                    activateWhenSafe(registration.waiting);
                }

                registration.addEventListener('updatefound', function () {
                    var newWorker = registration.installing;
                    if (!newWorker) return;

                    newWorker.addEventListener('statechange', function () {
                        // A controller already existing means this is a
                        // real update, not the very first install ever -
                        // the first install has nothing to "not interrupt"
                        // and should just activate immediately as normal.
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            activateWhenSafe(newWorker);
                        }
                    });
                });
            }).catch(function () {});

            var reloadedForUpdate = false;
            navigator.serviceWorker.addEventListener('controllerchange', function () {
                if (reloadedForUpdate) return;
                reloadedForUpdate = true;
                window.location.reload();
            });
        });
    }

    // FR-256/FR-262 Installability. The browser's own install prompt is
    // suppressed by preventDefault() and re-shown from our own button
    // instead (Chrome/Edge only fire this on their own install icon
    // otherwise, which is easy to miss - this is why "no install
    // button" was showing up: nothing in the app was listening for
    // this event at all before). Stored on `window` (not a module-
    // scoped variable) so it survives regardless of which page/layout
    // happens to render the actual button.
    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        window.__meetInstallPrompt = event;
        window.dispatchEvent(new CustomEvent('meet:pwa-installable'));
    });

    window.addEventListener('appinstalled', function () {
        window.__meetInstallPrompt = null;
        window.dispatchEvent(new CustomEvent('meet:pwa-installed'));
    });
</script>
