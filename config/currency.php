<?php

// Base currency and locale are tenant-editable settings stored in the database.
// See App\Modules\Core\Settings\CurrencySettings.

return [
    'providers' => [
        'exchangerate_api' => [
            'key' => env('EXCHANGERATE_API_KEY'),
            'url' => 'https://v6.exchangerate-api.com/v6',
        ],
    ],

    'default_provider' => 'exchangerate_api',

    // Cache duration in seconds. The free tier refreshes daily; 1 500 req/month limit.
    'cache_ttl' => 86400,
];
