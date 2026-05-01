<?php

namespace App\Modules\Billing\Settings;

use Spatie\LaravelSettings\Settings;

class BillingSettings extends Settings
{
    /** Day of month on which billing periods begin (1–28). */
    public int $billing_period_day = 1;

    public static function group(): string
    {
        return 'billing';
    }
}
