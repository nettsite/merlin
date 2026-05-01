<?php

namespace App\Modules\Billing\Settings;

use Spatie\LaravelSettings\Settings;

class BillingSettings extends Settings
{
    /** Day of month on which billing periods begin (1–28). */
    public int $billing_period_day = 1;

    /** Default AR control account ID (UUID of an asset account). Null until configured. */
    public ?string $default_receivable_account_id = null;

    /** Default payment term ID applied to new sales invoices when the client has none. */
    public ?string $default_payment_term_id = null;

    /** Tax liability account ID used on sales invoices. */
    public ?string $tax_liability_account_id = null;

    public static function group(): string
    {
        return 'billing';
    }
}
