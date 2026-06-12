<?php

// config for NettSite\NettMail
return [

    /*
     * Active mail driver. One of: php, smtp, resend, mailersend, mailgun, postmark, ses.
     */
    'driver' => env('NETTMAIL_DRIVER', 'php'),

    'drivers' => [

        'php' => [
            'command' => env('NETTMAIL_PHP_COMMAND', '/usr/sbin/sendmail -bs'),
        ],

        'smtp' => [
            'host' => env('NETTMAIL_SMTP_HOST'),
            'port' => env('NETTMAIL_SMTP_PORT', 587),
            'username' => env('NETTMAIL_SMTP_USERNAME'),
            'password' => env('NETTMAIL_SMTP_PASSWORD'),
            'encryption' => env('NETTMAIL_SMTP_ENCRYPTION', 'tls'),
        ],

        'resend' => [
            'api_key' => env('NETTMAIL_RESEND_API_KEY'),
            'webhook_secret' => env('NETTMAIL_RESEND_WEBHOOK_SECRET'),
        ],

        'mailersend' => [
            'api_key' => env('NETTMAIL_MAILERSEND_API_KEY'),
            'webhook_secret' => env('NETTMAIL_MAILERSEND_WEBHOOK_SECRET'),
        ],

        'mailgun' => [
            'api_key' => env('NETTMAIL_MAILGUN_API_KEY'),
            'domain' => env('NETTMAIL_MAILGUN_DOMAIN'),
            'webhook_secret' => env('NETTMAIL_MAILGUN_WEBHOOK_SECRET'),
        ],

        'postmark' => [
            'server_token' => env('NETTMAIL_POSTMARK_SERVER_TOKEN'),
            'webhook_secret' => env('NETTMAIL_POSTMARK_WEBHOOK_SECRET'),
        ],

        'ses' => [
            'access_key_id' => env('NETTMAIL_SES_ACCESS_KEY_ID'),
            'secret_access_key' => env('NETTMAIL_SES_SECRET_ACCESS_KEY'),
            'region' => env('NETTMAIL_SES_REGION'),
            'webhook_secret' => env('NETTMAIL_SES_WEBHOOK_SECRET'),
        ],

    ],

    /*
     * Default sender identity used until multi-sender support lands.
     */
    'from' => [
        'name' => env('NETTMAIL_FROM_NAME', env('MAIL_FROM_NAME')),
        'email' => env('NETTMAIL_FROM_ADDRESS', env('MAIL_FROM_ADDRESS')),
    ],

    'routes' => [
        'prefix' => env('NETTMAIL_ROUTES_PREFIX', 'nettmail'),
        'middleware' => ['web', 'auth'],
    ],

    /*
     * Unlayer project ID for the template editor, from unlayer.com.
     */
    'unlayer' => [
        'project_id' => env('NETTMAIL_UNLAYER_PROJECT_ID'),
    ],

    /*
     * Host app navigation group label, consumed by the host's nav.
     */
    'nav_group' => env('NETTMAIL_NAV_GROUP', 'NettMail'),

    'tracking' => [
        'opens' => env('NETTMAIL_TRACK_OPENS', true),
        'clicks' => env('NETTMAIL_TRACK_CLICKS', true),
    ],

    'bounces' => [
        'soft_limit' => env('NETTMAIL_BOUNCES_SOFT_LIMIT', 3),
        'soft_reset_days' => env('NETTMAIL_BOUNCES_SOFT_RESET_DAYS', 7),

        'mailbox' => [
            'host' => env('NETTMAIL_BOUNCE_MAILBOX_HOST'),
            'port' => env('NETTMAIL_BOUNCE_MAILBOX_PORT', 993),
            'username' => env('NETTMAIL_BOUNCE_MAILBOX_USERNAME'),
            'password' => env('NETTMAIL_BOUNCE_MAILBOX_PASSWORD'),
            'encryption' => env('NETTMAIL_BOUNCE_MAILBOX_ENCRYPTION', 'ssl'),
            'folder' => env('NETTMAIL_BOUNCE_MAILBOX_FOLDER', 'INBOX'),
            'processed_folder' => env('NETTMAIL_BOUNCE_MAILBOX_PROCESSED_FOLDER', 'Processed'),
            'unrecognised_folder' => env('NETTMAIL_BOUNCE_MAILBOX_UNRECOGNISED_FOLDER', 'Unrecognised'),
        ],
    ],

    'sending' => [
        /*
         * Maximum campaign sends per minute for queued dispatch.
         */
        'rate_limit' => env('NETTMAIL_SENDING_RATE_LIMIT', 60),
    ],

    'retention' => [
        /*
         * Number of years nettmail_sends / nettmail_events are retained before purge.
         */
        'send_log_years' => env('NETTMAIL_RETENTION_SEND_LOG_YEARS', 2),
    ],

    'compliance' => [
        /*
         * CAN-SPAM footer address, appended to broadcast campaign emails.
         */
        'physical_address' => env('NETTMAIL_PHYSICAL_ADDRESS'),
    ],

];
