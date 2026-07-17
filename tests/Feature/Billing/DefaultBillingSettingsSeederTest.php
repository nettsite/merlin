<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\PaymentTerm;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultBillingSettingsSeeder;
use Database\Seeders\PaymentTermSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use NettSite\NettMail\Models\Template;

it('wires up all default billing settings after chart of accounts and payment terms are seeded', function (): void {
    (new ChartOfAccountsSeeder)->run();
    (new PaymentTermSeeder)->run();
    (new DefaultBillingSettingsSeeder)->run();

    $settings = app(BillingSettings::class);

    $receivable = Account::where('code', '1100')->first();
    $bank = Account::where('code', '1000')->first();
    $eom = PaymentTerm::where('name', 'EOM')->first();
    $template = Template::where('name', 'Sales Invoice')->first();

    expect($settings->default_receivable_account_id)->toBe($receivable->id)
        ->and($settings->default_contra_account_id)->toBe($bank->id)
        ->and($settings->default_payment_term_id)->toBe($eom->id)
        ->and($settings->billing_period_day)->toBe(25)
        ->and($settings->base_email_template_id)->toBe($template->id);
});

it('fails loudly instead of leaving settings null when run before its dependencies', function (): void {
    expect(fn () => (new DefaultBillingSettingsSeeder)->run())
        ->toThrow(ModelNotFoundException::class);

    expect(app(BillingSettings::class)->default_receivable_account_id)->toBeNull();
});

it('is idempotent — a second run does not create a duplicate template', function (): void {
    (new ChartOfAccountsSeeder)->run();
    (new PaymentTermSeeder)->run();
    (new DefaultBillingSettingsSeeder)->run();
    (new DefaultBillingSettingsSeeder)->run();

    expect(Template::where('name', 'Sales Invoice')->count())->toBe(1);
});
