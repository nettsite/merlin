<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Services\DueDateCalculator;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\PaymentTerm;
use Carbon\Carbon;
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
    $firstOfNextMonth = PaymentTerm::where('name', '1st of Next Month')->first();
    $template = Template::where('name', 'Sales Invoice')->first();

    expect($settings->default_receivable_account_id)->toBe($receivable->id)
        ->and($settings->default_contra_account_id)->toBe($bank->id)
        ->and($settings->default_payment_term_id)->toBe($firstOfNextMonth->id)
        ->and($settings->billing_period_day)->toBe(25)
        ->and($settings->base_email_template_id)->toBe($template->id);
});

it('makes recurring invoices fall due on the 1st of the next month by default', function (): void {
    (new ChartOfAccountsSeeder)->run();
    (new PaymentTermSeeder)->run();
    (new DefaultBillingSettingsSeeder)->run();

    $term = PaymentTerm::find(app(BillingSettings::class)->default_payment_term_id);

    // 2027-05-01 is a Saturday: due date stays on the 1st, not the next business day.
    expect(app(DueDateCalculator::class)->calculate(Carbon::parse('2027-04-20'), $term, 25)->toDateString())
        ->toBe('2027-05-01')
        ->and(app(DueDateCalculator::class)->calculate(Carbon::parse('2026-11-20'), $term, 25)->toDateString())
        ->toBe('2026-12-01');
});

it('seeds a complete invoice email in NettMail and selects it', function (): void {
    (new ChartOfAccountsSeeder)->run();
    (new PaymentTermSeeder)->run();
    (new DefaultBillingSettingsSeeder)->run();

    $invoiceEmail = Template::find(app(BillingSettings::class)->invoice_email_template_id);

    expect($invoiceEmail?->name)->toBe('Invoice Email')
        ->and($invoiceEmail->html)->toContain('{{invoice_number}}')
        ->toContain('{{amount_due}}')
        ->not->toContain('[email_body]');
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
