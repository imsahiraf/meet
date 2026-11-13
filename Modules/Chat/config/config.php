<?php

return [
    'name' => 'Chat',

    // FR-053 - polling intervals (milliseconds), admin-configurable in a later phase.
    // Tried halving open_conversation_ms (1000 -> 500) to address a real
    // "messages arrive late" complaint - reverted. Real-world testing
    // showed it doubles poll request volume for every open conversation,
    // and on the kind of constrained shared/managed hosting this app
    // targets (see the no-queue-worker/cron-only architecture notes),
    // that's a genuine capacity risk, not just a dev-server artifact -
    // it saturated even this local box's request handling under a
    // handful of concurrent open conversations, causing unrelated page
    // loads to queue and time out. 1000ms is the safer default; a real
    // fix for "late" messages is a push-driven update (the app already
    // has FCM for background delivery - extending that to update an
    // ALREADY-OPEN conversation live, not just polling faster, is the
    // right direction for a future pass), not a tighter poll loop.
    'polling' => [
        'open_conversation_ms' => env('CHAT_POLL_OPEN_CONVERSATION_MS', 1000),
        'conversation_list_ms' => env('CHAT_POLL_CONVERSATION_LIST_MS', 8000),
        'notifications_ms' => env('CHAT_POLL_NOTIFICATIONS_MS', 3000),
    ],

    // FR-039 - typing indicator auto-expiry.
    'typing_timeout_seconds' => env('CHAT_TYPING_TIMEOUT_SECONDS', 8),

    // FR-041
    'edit_window_minutes' => env('CHAT_EDIT_WINDOW_MINUTES', 15),

    // FR-042
    'delete_for_everyone_window_minutes' => env('CHAT_DELETE_FOR_EVERYONE_WINDOW_MINUTES', 60),

    // FR-037
    'max_message_length' => env('CHAT_MAX_MESSAGE_LENGTH', 4096),

    // End-to-end encryption is only viable for conversations small enough
    // for per-recipient key-wrapping on every rotation - beyond this,
    // messages stay server-visible plaintext with a visible "not
    // encrypted" indicator, matching how real E2E messengers cap group
    // encryption rather than attempting it at broadcast-list scale.
    'e2e_max_group_members' => env('CHAT_E2E_MAX_GROUP_MEMBERS', 256),
];
