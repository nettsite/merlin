<?php

use App\Modules\Billing\Models\BillingEmailTemplate;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\User;
use App\Modules\Core\Settings\CompanySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Nettsite\NettMail\Core\Domain\Templates\TemplateType;
use NettSite\NettMail\Models\Template;

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

function billingEmailTemplate(string $name): Template
{
    return Template::create([
        'name' => $name,
        'type' => TemplateType::Transactional,
        'subject' => 'Subject',
        'html' => '<p>Body</p>',
    ]);
}

it('saves default invoice terms and footer on the Billing tab', function (): void {
    Livewire::test('pages.settings.index', ['tab' => 'billing'])
        ->set('tab', 'billing')
        ->set('invoiceEmailTemplateId', billingEmailTemplate('Invoice Email')->id)
        ->set('paymentEmailTemplateId', billingEmailTemplate('Payment Receipt Email')->id)
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

it('has no separate Email Templates tab', function (): void {
    Livewire::test('pages.settings.index')
        ->assertDontSee('Email Templates');
});

it('saves a reminder row\'s NettMail template, offset and enabled flag on the Billing tab', function (): void {
    $reminder = BillingEmailTemplate::create([
        'type' => 'reminder',
        'name' => 'Early Reminder',
        'subject' => 'unused',
        'body' => 'unused',
        'offset_days' => -3,
        'enabled' => true,
    ]);
    $nettMailTemplate = billingEmailTemplate('Early Reminder Email');

    Livewire::test('pages.settings.index', ['tab' => 'billing'])
        ->set('tab', 'billing')
        ->set('invoiceEmailTemplateId', billingEmailTemplate('Invoice Email')->id)
        ->set('paymentEmailTemplateId', billingEmailTemplate('Payment Receipt Email')->id)
        ->set("reminders.{$reminder->id}.nettmail_template_id", $nettMailTemplate->id)
        ->set("reminders.{$reminder->id}.offset_days", -5)
        ->set("reminders.{$reminder->id}.enabled", false)
        ->call('save')
        ->assertHasNoErrors();

    $reminder->refresh();

    expect($reminder->name)->toBe('Early Reminder')
        ->and($reminder->nettmail_template_id)->toBe($nettMailTemplate->id)
        ->and($reminder->offset_days)->toBe(-5)
        ->and($reminder->enabled)->toBeFalse();
});

it('requires a NettMail template to be chosen for each reminder', function (): void {
    $reminder = BillingEmailTemplate::create([
        'type' => 'reminder',
        'name' => 'Early Reminder',
        'subject' => 'unused',
        'body' => 'unused',
        'offset_days' => -3,
        'enabled' => true,
    ]);

    Livewire::test('pages.settings.index', ['tab' => 'billing'])
        ->set('tab', 'billing')
        ->set("reminders.{$reminder->id}.nettmail_template_id", '')
        ->call('save')
        ->assertHasErrors(["reminders.{$reminder->id}.nettmail_template_id"]);
});

it('requires permission to open the settings page', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages.settings.index')->assertForbidden();
});

it('saves a public https Email Logo URL and rejects anything else', function (): void {
    Livewire::test('pages.settings.index')
        ->set('companyName', 'NettSite')
        ->set('emailLogoUrl', 'http://nettsite.co.za/logo.png')
        ->call('save')
        ->assertHasErrors(['emailLogoUrl']);

    Livewire::test('pages.settings.index')
        ->set('companyName', 'NettSite')
        ->set('emailLogoUrl', ' https://nettsite.co.za/logo.png ')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(CompanySettings::class)->email_logo_url)->toBe('https://nettsite.co.za/logo.png');
});
