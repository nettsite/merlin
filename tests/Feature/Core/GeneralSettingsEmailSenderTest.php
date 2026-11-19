<?php

use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\User;
use App\Modules\Core\Settings\CompanySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('access-panel');
    $this->actingAs($user);
});

it('saves the outgoing email sender from General settings', function (): void {
    Livewire::test('pages.settings.index')
        ->set('companyName', 'NettSite')
        ->set('emailFromName', 'NettSite Support')
        ->set('emailFromAddress', 'support@nettsite.co.za')
        ->set('emailReplyToAddress', 'help@nettsite.co.za')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('saved', true);

    $company = app(CompanySettings::class);

    expect($company->email_from_name)->toBe('NettSite Support')
        ->and($company->email_from_address)->toBe('support@nettsite.co.za')
        ->and($company->email_reply_to_address)->toBe('help@nettsite.co.za');
});

it('loads the saved sender into the form', function (): void {
    $company = app(CompanySettings::class);
    $company->email_from_name = 'NettSite';
    $company->email_from_address = 'support@nettsite.co.za';
    $company->email_reply_to_address = 'support@nettsite.co.za';
    $company->save();

    Livewire::test('pages.settings.index')
        ->assertSet('emailFromName', 'NettSite')
        ->assertSet('emailFromAddress', 'support@nettsite.co.za')
        ->assertSet('emailReplyToAddress', 'support@nettsite.co.za');
});

it('rejects invalid sender addresses', function (): void {
    Livewire::test('pages.settings.index')
        ->set('companyName', 'NettSite')
        ->set('emailFromName', '')
        ->set('emailFromAddress', 'not-an-email')
        ->set('emailReplyToAddress', 'also bad')
        ->call('save')
        ->assertHasErrors(['emailFromName', 'emailFromAddress', 'emailReplyToAddress']);
});

it('saves the company website printed on invoices', function (): void {
    Livewire::test('pages.settings.index')
        ->set('companyName', 'NettSite')
        ->set('website', 'https://nettsite.co.za')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(CompanySettings::class)->website)->toBe('https://nettsite.co.za');
});

it('saves the banking details printed on invoices', function (): void {
    Livewire::test('pages.settings.index')
        ->set('companyName', 'NettSite')
        ->set('bankName', 'First National Bank')
        ->set('bankAccountName', 'NettSite')
        ->set('bankAccountNumber', ' 62508948033 ')
        ->set('bankBranchCode', '250655')
        ->call('save')
        ->assertHasNoErrors();

    $company = app(CompanySettings::class);

    expect($company->bank_name)->toBe('First National Bank')
        ->and($company->bank_account_name)->toBe('NettSite')
        ->and($company->bank_account_number)->toBe('62508948033')
        ->and($company->bank_branch_code)->toBe('250655');
});

it('saves default invoice terms and footer on the Billing tab', function (): void {
    Livewire::test('pages.settings.index', ['tab' => 'billing'])
        ->set('tab', 'billing')
        ->set('defaultInvoiceTerms', 'All invoices are payable by due date.')
        ->set('defaultInvoiceFooter', 'Our Bank Details: FNB')
        ->call('save')
        ->assertHasNoErrors();

    $billing = app(BillingSettings::class);

    expect($billing->default_invoice_terms)->toBe('All invoices are payable by due date.')
        ->and($billing->default_invoice_footer)->toBe('Our Bank Details: FNB');
});

it('shows the Outgoing Email section on the General settings URL', function (): void {
    $this->followingRedirects()
        ->get(route('settings.general'))
        ->assertOk()
        ->assertSee('Outgoing Email')
        ->assertSee('Reply-To Address');
});

it('requires permission to open the settings page', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages.settings.index')->assertForbidden();
});
