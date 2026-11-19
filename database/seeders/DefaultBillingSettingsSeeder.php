<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\PaymentTerm;
use Illuminate\Database\Seeder;
use Nettsite\NettMail\Core\Domain\Templates\TemplateType;
use NettSite\NettMail\Models\Template;

/**
 * Wires up the settings that Billing silently no-ops without (see
 * ClientReceivableAccountService::getOrCreateForClient() and
 * InvoiceEmailTemplateService::wrapInBaseTemplate()) — a fresh install with
 * these left unset means every sales invoice posts straight to the flat
 * control account instead of a per-client sub-account, with no error raised.
 *
 * Must run after ChartOfAccountsSeeder and PaymentTermSeeder — uses
 * firstOrFail() on every lookup so a misordered seeder call fails loudly
 * during seeding rather than leaving settings silently null.
 */
class DefaultBillingSettingsSeeder extends Seeder
{
    /** Starting point for the complete invoice email; restyled in NettMail's editor. */
    private const INVOICE_EMAIL_HTML = <<<'HTML'
        <div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#1f2937;max-width:600px;">
        <p>{{company_logo}}</p>
        <p>Dear {{first_name}},</p>
        <p>Please find attached invoice <strong>{{invoice_number}}</strong> for {{client_name}}.</p>
        <table style="border-collapse:collapse;margin:16px 0;">
        <tr><td style="padding:4px 16px 4px 0;color:#6b7280;">Invoice date</td><td style="padding:4px 0;">{{invoice_date}}</td></tr>
        <tr><td style="padding:4px 16px 4px 0;color:#6b7280;">Amount due</td><td style="padding:4px 0;"><strong>{{amount_due}}</strong></td></tr>
        <tr><td style="padding:4px 16px 4px 0;color:#6b7280;">Due date</td><td style="padding:4px 0;">{{due_date}}</td></tr>
        </table>
        <p>Our banking details are on the invoice. Please use the invoice number as your payment reference.</p>
        <p>If you have any questions about this invoice, just reply to this email.</p>
        <p>Thank you,<br>{{company_name}}</p>
        </div>
        HTML;

    public function run(): void
    {
        $receivableAccount = Account::where('code', '1100')->firstOrFail();
        $bankAccount = Account::where('code', '1000')->firstOrFail();
        $defaultTerm = PaymentTerm::where('name', '1st of Next Month')->firstOrFail();

        $template = Template::firstOrCreate(
            ['name' => 'Sales Invoice'],
            [
                'type' => TemplateType::Transactional,
                'subject' => 'Invoice from {{company_name}}',
                'html' => '[email_body]',
            ],
        );

        $invoiceEmail = Template::firstOrCreate(
            ['name' => 'Invoice Email'],
            [
                'type' => TemplateType::Transactional,
                'subject' => 'Invoice {{invoice_number}} from {{company_name}}',
                'html' => self::INVOICE_EMAIL_HTML,
            ],
        );

        $settings = app(BillingSettings::class);
        $settings->default_receivable_account_id = $receivableAccount->id;
        $settings->default_contra_account_id = $bankAccount->id;
        $settings->default_payment_term_id = $defaultTerm->id;
        $settings->billing_period_day = 25;
        $settings->base_email_template_id = $template->id;
        $settings->invoice_email_template_id = $invoiceEmail->id;
        $settings->save();
    }
}
