<?php

namespace App\Modules\Purchasing\Settings;

use Spatie\LaravelSettings\Settings;

class PurchasingSettings extends Settings
{
    /** Account code used as the default Accounts Payable account on new invoices. */
    public string $default_payable_account = '2000';

    /** Default tax rate applied to new invoice lines (percentage). */
    public float $tax_default_rate = 15.00;

    /** Label shown for tax (e.g. "VAT", "GST"). */
    public string $tax_label = 'VAT';

    /** Minimum LLM confidence score required to consider autonomous posting. */
    public float $autopost_confidence = 0.90;

    /** Maximum amount difference (in base currency) allowed for pattern-based auto-posting. */
    public float $amount_tolerance = 10.0;

    /** Minimum description similarity score (0–100) required for pattern-based auto-posting. */
    public float $description_similarity = 60.0;

    public static function group(): string
    {
        return 'purchasing';
    }
}
