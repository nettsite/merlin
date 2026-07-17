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
    public function run(): void
    {
        $receivableAccount = Account::where('code', '1100')->firstOrFail();
        $bankAccount = Account::where('code', '1000')->firstOrFail();
        $eomTerm = PaymentTerm::where('name', 'EOM')->firstOrFail();

        $template = Template::firstOrCreate(
            ['name' => 'Sales Invoice'],
            [
                'type' => TemplateType::Transactional,
                'subject' => 'Invoice from {{company_name}}',
                'html' => '[email_body]',
            ],
        );

        $settings = app(BillingSettings::class);
        $settings->default_receivable_account_id = $receivableAccount->id;
        $settings->default_contra_account_id = $bankAccount->id;
        $settings->default_payment_term_id = $eomTerm->id;
        $settings->billing_period_day = 25;
        $settings->base_email_template_id = $template->id;
        $settings->save();
    }
}
