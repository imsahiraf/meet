<?php

// SRS FR-097 - Google Public STUN is the documented v1 default.
// FR-205 also lists "STUN/TURN servers" as an admin-configurable
// setting - TURN itself is optional (STUN-only still works on most
// networks), but a real TURN relay is what actually fixes calls that
// drop/stay choppy on restrictive NATs/firewalls, which pure STUN
// cannot solve. Set CALLS_TURN_URL (+ optionally USERNAME/CREDENTIAL)
// to enable it without any code change.
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
    'name' => 'Calls',

    'ice_servers' => $iceServers,

    // Pre-gathers ICE candidates before the offer/answer is even
    // created, shaving connection-setup latency.
    'ice_candidate_pool_size' => 10,

    // No poll interval for call signaling is named anywhere in the
    // docs (see Phase 4 research notes). Lowered from the original
    // 1000ms after a reported perceptible delay/choppiness during
    // real calls - signaling (offer/answer/ICE exchange) is the part
    // this interval controls; once connected, media flows directly
    // peer-to-peer and is unaffected by this value, but a slower
    // interval here does make call setup and reconnection feel
    // sluggish, which is what was being noticed.
    'signal_poll_ms' => env('CALLS_SIGNAL_POLL_MS', 400),
    'incoming_call_poll_ms' => env('CALLS_INCOMING_POLL_MS', 2000),

    'ring_timeout_seconds' => env('CALLS_RING_TIMEOUT_SECONDS', 30),

    // Backstop for a call nobody ever hung up. There is no persistent
    // worker and no per-participant heartbeat in this app, so an accepted
    // call whose participants' browsers all vanished has nothing to
    // close it - and since call_participants is now what the busy check
    // and the incoming-call poll both read, such a row would leave those
    // people permanently "on a call". See
    // MarkMissedCallsJob::endAbandonedCalls(). Generous on purpose: this
    // is cleanup for abandoned rows, not a limit anyone should ever hit
    // in a real conversation.
    'max_duration_seconds' => env('CALLS_MAX_DURATION_SECONDS', 12 * 3600),
];
