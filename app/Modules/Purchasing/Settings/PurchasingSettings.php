<?php

namespace App\Modules\Purchasing\Settings;

use Spatie\LaravelSettings\Settings;

class PurchasingSettings extends Settings
{
    /** Account code used as the default Accounts Payable account on new invoices. */
    public string $default_payable_account = '2000';

    /** Default account credited when a purchase invoice payment is recorded (e.g. bank, or Drawings if paid from a personal card). Null until configured. */
    public ?string $default_payment_contra_account_id = null;

    /** Default tax rate applied to new invoice lines (percentage). */
    public float $tax_default_rate = 15.00;

    /** Label shown for tax (e.g. "VAT", "GST"). */
    public string $tax_label = 'VAT';

    /** Minimum LLM confidence score required to consider autonomous posting. */
    public float $autopost_confidence = 0.90;

    /** Minimum fast-model confidence required to accept its extraction without falling back to the stronger model. */
    public float $fallback_confidence = 0.80;

    /** Maximum amount difference (in base currency) allowed for pattern-based auto-posting. */
    public float $amount_tolerance = 10.0;

    /** Minimum description similarity score (0–100) required for pattern-based auto-posting. */
    public float $description_similarity = 60.0;

    /** Minimum match confidence required to auto-merge a payment notification into its matching purchase invoice. */
    public float $payment_match_auto_confidence = 0.80;

    public static function group(): string
    {
        return 'purchasing';
    }
}
