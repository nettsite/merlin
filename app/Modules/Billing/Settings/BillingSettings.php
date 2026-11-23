<?php

namespace App\Modules\Billing\Settings;

use Spatie\LaravelSettings\Settings;

class BillingSettings extends Settings
{
    /** Day of month on which billing periods begin (1–28). */
    public int $billing_period_day = 25;

    /** Default AR control account ID (UUID of an asset account). Null until configured. */
    public ?string $default_receivable_account_id = null;

    /** Default bank account ID used when recording payments. */
    public ?string $default_contra_account_id = null;

    /** Default payment term ID applied to new sales invoices when the client has none. */
    public ?string $default_payment_term_id = null;

    /** Tax liability account ID used on sales invoices. */
    public ?string $tax_liability_account_id = null;

    /** NettMail/Unlayer template used as the branded wrapper for all billing emails. Must contain {{email_body}}. */
    public ?string $base_email_template_id = null;

    /** NettMail template used as the complete invoice email (subject + body). Null = Settings text in the base layout. */
    public ?string $invoice_email_template_id = null;

    /** NettMail template used as the complete payment receipt email (subject + body). Null = Email Templates tab text. */
    public ?string $payment_email_template_id = null;

    /** Floor for the next payment/receipt number. Null = continue counting from the highest existing number. Auto-advances after each payment. */
    public ?int $next_receipt_number = null;

    /** Printed under "Invoice Terms:" when an invoice has no terms of its own. */
    public string $default_invoice_terms = '';

    /** Printed at the foot of the invoice (e.g. bank details) when an invoice has no footer of its own. */
    public string $default_invoice_footer = '';

    public static function group(): string
    {
        return 'billing';
    }
}
