<?php

use App\Modules\Billing\Services\BillingService;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\Address;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Services\PartyService;
use App\Modules\Core\Settings\CompanySettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $attributes
 */
function pdfInvoice(array $attributes = []): Document
{
    $client = app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Fitting & Handle Distributors (Pty) Ltd',
        'registration_number' => '2009/021450/07',
        'tax_number' => '4540254788',
        'status' => 'active',
    ], ['client']);

    Address::create([
        'party_id' => $client->id,
        'type' => 'billing',
        'line_1' => '36 Newton Street',
        'city' => 'Vilage Main',
        'state_province' => 'Johannesburg',
        'country' => 'ZA',
        'is_primary' => true,
        'is_active' => true,
    ]);

    $invoice = app(BillingService::class)->createDraft($client, ['issue_date' => '2026-10-26']);
    $invoice->update(array_merge(['document_number' => 'R6551'], $attributes));

    $invoice->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Proactive website management monthly retainer November 2026',
        'quantity' => 10,
        'unit_price' => 300,
        'discount_percent' => 0,
    ]);

    return $invoice->fresh(['party.business', 'lines']);
}

function renderInvoicePdf(Document $invoice): string
{
    return view('pdf.sales-invoice', ['invoice' => $invoice, 'lines' => $invoice->lines])->render();
}

it('lays out the invoice like the InvoiceNinja design', function (): void {
    $company = app(CompanySettings::class);
    $company->name = 'NettSite';
    $company->website = 'https://nettsite.co.za';
    $company->save();

    $html = renderInvoicePdf(pdfInvoice());

    expect($html)
        ->toContain('Invoice issued to:')
        ->toContain('Fitting &amp; Handle Distributors (Pty) Ltd')
        ->toContain('Vilage Main, Johannesburg')
        ->toContain('4540254788')
        ->toContain('2009/021450/07')
        ->toContain('R6551')
        ->toContain('October 26, 2026')
        ->toContain('R3,000.00')
        ->toContain('Paid to Date')
        ->toContain('Balance Due')
        ->toContain('https://nettsite.co.za')
        ->not->toContain('InvoiceNinja');
});

it('prints the invoice terms and footer, converting Ninja HTML to plain text', function (): void {
    $html = renderInvoicePdf(pdfInvoice([
        'terms' => '<p>All invoices are payable by due date.</p>',
        'footer' => "Our Bank Details:\r\nFirst National Bank",
    ]));

    expect($html)
        ->toContain('Invoice Terms:')
        ->toContain('All invoices are payable by due date.')
        ->not->toContain('&lt;p&gt;')
        ->toContain('Our Bank Details:<br />')
        ->toContain('First National Bank');
});

it('falls back to the billing default terms and footer when the invoice has none', function (): void {
    $billing = app(BillingSettings::class);
    $billing->default_invoice_terms = 'Default terms apply.';
    $billing->default_invoice_footer = 'Default bank details.';
    $billing->save();

    $html = renderInvoicePdf(pdfInvoice(['terms' => null, 'footer' => null]));

    expect($html)
        ->toContain('Default terms apply.')
        ->toContain('Default bank details.');
});

it('escapes HTML in terms and footer instead of rendering it', function (): void {
    $html = renderInvoicePdf(pdfInvoice(['footer' => '<script>alert(1)</script>Bank']));

    expect($html)->not->toContain('<script>')
        ->toContain('Bank');
});

it('renders a one-page PDF', function (): void {
    $invoice = pdfInvoice();

    $pdf = Pdf::loadView('pdf.sales-invoice', ['invoice' => $invoice, 'lines' => $invoice->lines])->output();

    expect($pdf)->toStartWith('%PDF')
        ->and(preg_match_all('/\/Type\s*\/Page[^s]/', $pdf))->toBe(1);
});

it('prints the banking details for EFT payment from General settings', function (): void {
    $company = app(CompanySettings::class);
    $company->bank_name = 'First National Bank';
    $company->bank_account_name = 'NettSite';
    $company->bank_account_number = '62508948033';
    $company->bank_branch_code = '250655';
    $company->save();

    expect(renderInvoicePdf(pdfInvoice()))
        ->toContain('Banking Details for EFT Payment')
        ->toContain('First National Bank')
        ->toContain('Account Name')
        ->toContain('62508948033')
        ->toContain('250655');
});

it('omits the banking block when no bank details are set', function (): void {
    expect(renderInvoicePdf(pdfInvoice()))->not->toContain('Banking Details for EFT Payment');
});

it('skips a footer that repeats the configured bank account', function (): void {
    $company = app(CompanySettings::class);
    $company->bank_account_number = '62508948033';
    $company->save();

    $html = renderInvoicePdf(pdfInvoice([
        'footer' => '<p>Our Bank Details: First National Bank Branch Code 250655 Account Number 6250 8948 033</p>',
    ]));

    expect($html)->toContain('62508948033')
        ->not->toContain('Our Bank Details');
});

it('still prints a footer that is not about the bank account', function (): void {
    $company = app(CompanySettings::class);
    $company->bank_account_number = '62508948033';
    $company->save();

    expect(renderInvoicePdf(pdfInvoice(['footer' => 'Thank you for your business.'])))
        ->toContain('Thank you for your business.');
});
