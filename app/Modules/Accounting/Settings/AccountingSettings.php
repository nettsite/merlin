<?php

namespace App\Modules\Accounting\Settings;

use Spatie\LaravelSettings\Settings;

class AccountingSettings extends Settings
{
    /** Financial year start month (1 = January … 12 = December). */
    public int $financial_year_start_month;

    public static function group(): string
    {
        return 'accounting';
    }
}
