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

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
        'model_fast' => env('ANTHROPIC_MODEL_FAST', 'claude-haiku-4-5-20251001'),

        // Final fallback rung. Extraction escalates fast -> model -> backup when a
        // model is retired (not_found_error). Ordered ladder, dead rungs skipped.
        'model_backup' => env('ANTHROPIC_MODEL_BACKUP', 'claude-opus-4-8'),

        // Comma-separated alert recipients for model-unavailable notifications.
        'alert_recipients' => env('ANTHROPIC_ALERT_RECIPIENTS', 'merlin@nettsite.co.za'),

        // How long (seconds) a model stays marked "down" after a not_found_error,
        // suppressing repeat 404s and repeat alerts until the next health check.
        'down_ttl' => (int) env('ANTHROPIC_MODEL_DOWN_TTL', 3600),
    ],

];
