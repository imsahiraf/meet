<?php

return [
    // Server-side (Admin SDK) - used to actually send pushes via FCM.
    // FIREBASE_PRIVATE_KEY is stored in .env with literal \n sequences
    // (not real newlines - a .env value can't contain them), converted
    // back here since the SDK needs a real multi-line PEM string.
    'project_id' => env('FIREBASE_PROJECT_ID'),
    'client_email' => env('FIREBASE_CLIENT_EMAIL'),
    'private_key' => str_replace('\\n', "\n", (string) env('FIREBASE_PRIVATE_KEY')),

    // Client-side (Web SDK) - not secret, safe to expose in the page
    // like any Firebase web app's shipped config. FIREBASE_VAPID_KEY is
    // a *different* key from the Admin SDK credentials above (Project
    // Settings -> Cloud Messaging -> Web Push certificates), used only
    // to request a device token via getToken().
    'api_key' => env('FIREBASE_API_KEY'),
    'auth_domain' => env('FIREBASE_AUTH_DOMAIN'),
    'storage_bucket' => env('FIREBASE_STORAGE_BUCKET'),
    'messaging_sender_id' => env('FIREBASE_MESSAGING_SENDER_ID'),
    'app_id' => env('FIREBASE_APP_ID'),
    'vapid_key' => env('FIREBASE_VAPID_KEY'),
];
