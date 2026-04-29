<?php

namespace App\Modules\Core\Settings;

use Spatie\LaravelSettings\Settings;

class CurrencySettings extends Settings
{
    public string $base_currency = 'ZAR';

    public string $locale = 'en_ZA';

    public static function group(): string
    {
        return 'currency';
    }
}
