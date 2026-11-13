<?php

return [
    'name' => 'Mailbox',

    // Domain of the free Meet addresses (name@domain). Its mail is piped to `php artisan mailbox:pipe` by cPanel
    // (Documentation/MAIL_CPANEL_SETUP.md). Empty = own addresses are switched off; external accounts still work.
    'domain' => '', // personal copy: no own addresses, other accounts only
    // Other domains of the same people (comma list, e.g. example.net): rahul.user@example.net reaches rahul.user@example.com. Each one
    // must be a domain of the same cPanel account; its catch-all is piped to Meet too.
    'alias_domains' => '',
    // Every personal address ends with this before the @: rahul.user@example.com (company cPanel mailboxes never clash).
    'local_suffix' => env('MEET_MAIL_SUFFIX', '.user'),

    // Storage of the own mailbox (messages + attachments). Free users: 30 MB. Connecting Google moves older mail to the
    // user's Google Drive, so the mailbox keeps working past the free size. Meet Insights users get the size of their
    // package (sent by Insights in the sign-in token, claim "mail_mb").
    'free_quota_mb' => (int) env('MEET_MAIL_FREE_MB', 30),
    'archive_after_days' => (int) env('MEET_MAIL_ARCHIVE_DAYS', 30),
    'archive_when_used_pct' => 80,

    'max_message_mb' => (int) env('MEET_MAIL_MAX_MESSAGE_MB', 20),
    'sync_initial' => 200, // messages taken per folder the first time an external account syncs

    // Google (same OAuth client as "Sign in with Google"; add the redirect URL below in Google Cloud):
    // Gmail over IMAP / SMTP (XOAUTH2), Drive (only files this app creates) and Contacts (read only). Synced every hour.
    'google' => [
        'redirect' => env('MEET_MAIL_GOOGLE_REDIRECT', env('APP_URL') . '/mail/google/callback'),
        'scopes' => ['openid', 'email', 'profile', 'https://mail.google.com/', 'https://www.googleapis.com/auth/drive.file',
            'https://www.googleapis.com/auth/contacts.readonly'],
    ],

    // Ready settings for the add-account form. Most providers need an "app password" when 2-step login is on.
    'presets' => [
        'gmail' => ['label' => 'Gmail', 'imap' => ['imap.gmail.com', 993, 'ssl'], 'smtp' => ['smtp.gmail.com', 465, 'ssl'], 'help' => 'Use "Connect Google" (recommended) or a Google app password.'],
        'yahoo' => ['label' => 'Yahoo Mail', 'imap' => ['imap.mail.yahoo.com', 993, 'ssl'], 'smtp' => ['smtp.mail.yahoo.com', 465, 'ssl'], 'help' => 'Create an app password in Yahoo account security.'],
        'rediffmail' => ['label' => 'Rediffmail', 'imap' => ['imap.rediffmail.com', 993, 'ssl'], 'smtp' => ['smtp.rediffmail.com', 465, 'ssl'], 'help' => 'Turn on POP/IMAP access in Rediffmail settings first.'],
        'rediffpro' => ['label' => 'Rediffmail Pro', 'imap' => ['imap.rediffmailpro.com', 993, 'ssl'], 'smtp' => ['smtp.rediffmailpro.com', 465, 'ssl'], 'help' => ''],
        'zoho' => ['label' => 'Zoho Mail', 'imap' => ['imap.zoho.in', 993, 'ssl'], 'smtp' => ['smtp.zoho.in', 465, 'ssl'], 'help' => 'Zoho accounts outside India use imap.zoho.com / smtp.zoho.com. Enable IMAP access in Zoho settings.'],
        'outlook' => ['label' => 'Outlook / Hotmail', 'imap' => ['outlook.office365.com', 993, 'ssl'], 'smtp' => ['smtp.office365.com', 587, 'tls'], 'help' => 'Microsoft may require an app password.'],
        'other' => ['label' => 'Other (IMAP / SMTP)', 'imap' => ['', 993, 'ssl'], 'smtp' => ['', 465, 'ssl'], 'help' => 'Ask your mail provider for the IMAP and SMTP server names.'],
    ],
];
