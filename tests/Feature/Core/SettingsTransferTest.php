<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Models\BillingEmailTemplate;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\PaymentTerm;
use App\Modules\Core\Services\SettingsTransferService;
use App\Modules\Core\Settings\CompanySettings;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\PaymentTermSeeder;
use Illuminate\Support\Facades\Storage;
use Nettsite\NettMail\Core\Domain\Templates\TemplateType;
use NettSite\NettMail\Models\Template;

beforeEach(function (): void {
    Storage::fake('public');
    (new ChartOfAccountsSeeder)->run();
    (new PaymentTermSeeder)->run();
});

/**
 * Sets up a "test instance": settings that reference records, a NettMail
 * template, a billing email and a logo; returns the export.
 *
 * @return array<string, mixed>
 */
function configuredExport(): array
{
    $template = Template::create([
        'name' => 'Sales Invoice',
        'type' => TemplateType::Transactional,
        'subject' => 'Invoice {{invoice_number}}',
        'design' => ['pages' => []],
        'html' => '<p>Dear {{first_name}}</p>',
    ]);

    BillingEmailTemplate::create([
        'type' => 'reminder', 'name' => 'First Reminder', 'subject' => 'Reminder',
        'body' => '<p>Please pay</p>', 'offset_days' => 1, 'enabled' => true,
    ]);

    Storage::disk('public')->put('company/logo.png', 'png-bytes');

    $company = app(CompanySettings::class);
    $company->name = 'NettSite';
    $company->bank_account_number = '62508948033';
    $company->logo_path = 'company/logo.png';
    $company->save();

    $billing = app(BillingSettings::class);
    $billing->default_receivable_account_id = Account::where('code', '1100')->value('id');
    $billing->default_payment_term_id = PaymentTerm::where('name', '1st of Next Month')->value('id');
    $billing->invoice_email_template_id = $template->id;
    $billing->save();

    return app(SettingsTransferService::class)->export();
}

it('exports references as stable keys, not database ids', function (): void {
    $export = configuredExport();

    expect($export['settings']['billing']['default_receivable_account_id'])->toBe(['$ref' => 'account', 'code' => '1100'])
        ->and($export['settings']['billing']['default_payment_term_id'])->toBe(['$ref' => 'payment_term', 'name' => '1st of Next Month'])
        ->and($export['settings']['billing']['invoice_email_template_id'])->toBe(['$ref' => 'nettmail_template', 'name' => 'Sales Invoice'])
        ->and($export['settings']['company']['logo_path']['base64'])->toBe(base64_encode('png-bytes'))
        ->and(array_column($export['nettmail_templates'], 'name'))->toContain('Sales Invoice');
});

it('re-points references to the matching records in the target database', function (): void {
    $export = configuredExport();

    // Simulate a fresh database: same codes and names, different ids.
    $oldAccount = Account::where('code', '1100')->first();
    $newAccount = $oldAccount->replicate();
    $oldAccount->update(['code' => '1199']);
    $newAccount->code = '1100';
    $newAccount->save();

    $oldTerm = PaymentTerm::where('name', '1st of Next Month')->first();
    $oldTerm->update(['name' => 'Retired term']);
    $newTerm = PaymentTerm::create(['name' => '1st of Next Month', 'rule' => $oldTerm->rule, 'day_of_month' => 1]);

    Template::query()->delete();
    BillingEmailTemplate::query()->delete();
    Storage::disk('public')->delete('company/logo.png');

    $company = app(CompanySettings::class);
    $company->name = '';
    $company->logo_path = null;
    $company->save();

    $report = app(SettingsTransferService::class)->import($export);

    $billing = app(BillingSettings::class);
    $company = app(CompanySettings::class);

    expect($report['skipped'])->toBe([])
        ->and($billing->default_receivable_account_id)->toBe($newAccount->id)
        ->and($billing->default_payment_term_id)->toBe($newTerm->id)
        ->and($billing->invoice_email_template_id)->toBe(Template::where('name', 'Sales Invoice')->value('id'))
        ->and(Template::where('name', 'Sales Invoice')->first()->design)->toBe(['pages' => []])
        ->and(BillingEmailTemplate::where('name', 'First Reminder')->value('body'))->toBe('<p>Please pay</p>')
        ->and($company->name)->toBe('NettSite')
        ->and($company->bank_account_number)->toBe('62508948033')
        ->and($company->logo_path)->toBe('company/logo.png')
        ->and(Storage::disk('public')->get('company/logo.png'))->toBe('png-bytes');
});

it('skips and reports references that do not exist in the target database', function (): void {
    $export = configuredExport();
    $export['settings']['billing']['default_receivable_account_id'] = ['$ref' => 'account', 'code' => '7777'];

    $report = app(SettingsTransferService::class)->import($export);

    expect($report['skipped'])->toBe(['billing.default_receivable_account_id: no account with code 7777']);
});

it('changes nothing on a dry run', function (): void {
    $export = configuredExport();
    $export['settings']['company']['name'] = 'Changed Name';
    Storage::disk('public')->delete('company/logo.png');

    $report = app(SettingsTransferService::class)->import($export, dryRun: true);

    expect($report['applied'])->toContain('company.name')
        ->and(app(CompanySettings::class)->name)->toBe('NettSite')
        ->and(Storage::disk('public')->exists('company/logo.png'))->toBeFalse();
});

it('round-trips through the export and import commands', function (): void {
    configuredExport();
    $path = tempnam(sys_get_temp_dir(), 'settings_');

    $this->artisan('settings:export', ['path' => $path])->assertSuccessful();

    expect(substr(sprintf('%o', fileperms($path)), -4))->toBe('0600');

    $this->artisan('settings:import', ['path' => $path, '--force' => true])
        ->expectsOutputToContain('0 skipped')
        ->assertSuccessful();

    unlink($path);
});

it('rejects a file that is not a settings export', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'settings_');
    file_put_contents($path, json_encode(['hello' => 'world']));

    expect(fn () => app(SettingsTransferService::class)->import(json_decode(file_get_contents($path), true)))
        ->toThrow(InvalidArgumentException::class);

    unlink($path);
});
