<?php

// See Modules/Calls/config/config.php for the STUN/TURN rationale -
// meeting mesh calls reuse the same servers/env vars.
$iceServers = [
    ['urls' => env('CALLS_STUN_URL', 'stun:stun.l.google.com:19302')],
];

if ($turnUrl = env('CALLS_TURN_URL')) {
    $iceServers[] = array_filter([
        'urls' => $turnUrl,
        'username' => env('CALLS_TURN_USERNAME'),
        'credential' => env('CALLS_TURN_CREDENTIAL'),
    ]);
}

return [
    'name' => 'Meetings',

    // Meeting summary from the live captions (Claude). Empty key = a plain summary made without AI (who spoke, how long, key lines).
    'ai' => [
        'key' => env('MEET_AI_KEY', env('ANTHROPIC_API_KEY', '')),
        'model' => env('MEET_AI_MODEL', 'claude-opus-5-5'),
    ],
    // Recordings uploaded from the host's browser (MB); bigger ones stay only as the host's download.
    'recording_max_mb' => (int) env('MEET_RECORDING_MAX_MB', 300),

    'ice_servers' => $iceServers,
    'ice_candidate_pool_size' => 10,

    // FR-137/FR-140 "Maximum Participants (Admin Configurable)" - no
    // default number is given anywhere in the docs. 8 is a judgment
    // call sized for full-mesh WebRTC (each pair negotiates its own
    // peer connection - 8 participants = 28 simultaneous connections
    // app-wide), consistent with the documented v1 non-goal of "large
    // group video with dozens of participants."
    'max_participants' => env('MEETINGS_MAX_PARTICIPANTS', 8),

    // Lowered from 1000/3000ms - see Modules/Calls/config/config.php's
    // comment on the same values for why.
    'signal_poll_ms' => env('MEETINGS_SIGNAL_POLL_MS', 400),
    'incoming_call_poll_ms' => env('MEETINGS_INCOMING_POLL_MS', 2000),

    // FR-317 - guest session default duration; admin-configurable options
    // per the docs are 5/10/15/30/60 minutes or Unlimited (off by default).
    'guest_session_minutes' => env('MEETINGS_GUEST_SESSION_MINUTES', 15),

    // A participant who's abandoned the tab (crash, force-quit, network
    // drop) rather than clicking Leave has no left_at set - roster()
    // treats anyone not heartbeated (via their own poll hitting
    // /participants or /guest/{session}/roster) within this window as
    // gone, so their tile/peer connection actually gets torn down
    // instead of lingering as a phantom "second participant" forever.
    // 15x the poll interval above - generous tolerance for a brief
    // network hiccup without masking a real disconnect for long.
    'stale_participant_seconds' => env('MEETINGS_STALE_PARTICIPANT_SECONDS', 30),

    // FR-166 default reminder timing for a newly scheduled meeting.
    'default_reminder_minutes' => env('MEETINGS_DEFAULT_REMINDER_MINUTES', 15),

    // FR-138 "Meeting media streams shall not pass through the Laravel
    // application server" - archive/ICS mail settings reuse the existing
    // mail configuration; nothing meeting-specific needed here.
];
