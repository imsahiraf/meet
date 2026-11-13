<?php

return [
    'name' => 'Authentication',

    // FR-001 / FR-002 / FR-003 - configurable per Admin Panel in a later phase.
    'otp' => [
        'length' => env('AUTH_OTP_LENGTH', 6),
        'expires_in_minutes' => env('AUTH_OTP_EXPIRES_IN_MINUTES', 10),
        'max_verify_attempts' => env('AUTH_OTP_MAX_VERIFY_ATTEMPTS', 5),
        'resend_cooldown_seconds' => env('AUTH_OTP_RESEND_COOLDOWN_SECONDS', 60),
        'max_resend_per_day' => env('AUTH_OTP_MAX_RESEND_PER_DAY', 5),
    ],

    // FR-005 / FR-006
    'password_reset' => [
        'token_expires_in_minutes' => env('AUTH_PASSWORD_RESET_EXPIRES_IN_MINUTES', 60),
    ],

    // FR-004 rate limiting
    'login' => [
        'max_attempts' => env('AUTH_LOGIN_MAX_ATTEMPTS', 5),
        'decay_seconds' => env('AUTH_LOGIN_DECAY_SECONDS', 60),
    ],
];
