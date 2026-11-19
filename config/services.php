<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // cPanel API of this account (mail:cpanel-setup points the mail catch-all at Meet). Token: cPanel > Security > Manage API Tokens.
    'cpanel' => [
        'user' => env('CPANEL_USER', ''),
        'token' => env('CPANEL_API_TOKEN', ''),
        'host' => env('CPANEL_HOST', 'localhost'),
        'port' => (int) env('CPANEL_PORT', 2083),
    ],

    // Signed entry links from Insights by Meet (same secret as Insights > Control center > Integrations > Meet)
    'insights' => [
        'sso_secret' => env('INSIGHTS_SSO_SECRET', ''),
        'audience' => env('INSIGHTS_SSO_AUDIENCE', 'meet'),
        'url' => env('INSIGHTS_URL', 'https://github.com/imsahiraf/meet'),
    ],

    // Pass for the free Meet tools served by Insights by Meet (same secret as TOOLS_PASS_SECRET in Insights)
    'tools' => [
        'pass_secret' => env('TOOLS_PASS_SECRET', ''),
        'hosts' => env('TOOLS_PASS_HOSTS', 'example.com,example.net,example.in,example.org,example.biz'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

];
