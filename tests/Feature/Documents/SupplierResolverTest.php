<?php

use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\PartyService;
use App\Modules\Purchasing\DTO\ExtractedInvoice;
use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Services\SupplierResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
    $this->resolver = app(SupplierResolver::class);
    $this->service = app(PartyService::class);
});

/**
 * Build a minimal ExtractedInvoice with only the fields SupplierResolver cares about.
 */
function extractedInvoice(?string $supplierName = null, ?string $taxNumber = null): ExtractedInvoice
{
    return new ExtractedInvoice(
        supplierName: $supplierName,
        supplierTaxNumber: $taxNumber,
        supplierEmail: null,
        supplierPhone: null,
        invoiceNumber: null,
        issueDate: null,
        dueDate: null,
        currency: config('currency.base', 'ZAR'),
        subtotal: 0,
        taxTotal: 0,
        total: 0,
        lines: [],
        confidence: 0.9,
        warnings: [],
    );
}

it('does nothing when party_id is already set', function (): void {
    $supplier = $this->service->createBusiness(
        ['business_type' => 'company', 'legal_name' => 'Existing Supplier'],
        relationships: ['supplier']
    );

    $document = Document::factory()->purchaseInvoice()->create(['party_id' => $supplier->id]);

    $this->resolver->resolve($document, extractedInvoice('Some Other Name'));

    expect($document->fresh()->party_id)->toBe($supplier->id);
});

it('matches supplier by tax number', function (): void {
    $supplier = $this->service->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Tax Match Supplier',
        'tax_number' => '4011234567',
    ], relationships: ['supplier']);

    $document = Document::factory()->purchaseInvoice()->create(['party_id' => null]);

    $this->resolver->resolve($document, extractedInvoice('Different Name', '4011234567'));

    expect($document->fresh()->party_id)->toBe($supplier->id);
});

it('matches supplier by trading name', function (): void {
    $supplier = $this->service->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Full Legal Name Pty Ltd',
        'trading_name' => 'Short Name',
    ], relationships: ['supplier']);

    $document = Document::factory()->purchaseInvoice()->create(['party_id' => null]);

    $this->resolver->resolve($document, extractedInvoice('Short Name'));

    expect($document->fresh()->party_id)->toBe($supplier->id);
});

it('matches supplier by legal name when no trading name', function (): void {
    $supplier = $this->service->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Unique Legal Corp',
    ], relationships: ['supplier']);

    $document = Document::factory()->purchaseInvoice()->create(['party_id' => null]);

    $this->resolver->resolve($document, extractedInvoice('Unique Legal Corp'));

    expect($document->fresh()->party_id)->toBe($supplier->id);
});

it('creates a pending supplier when no match is found', function (): void {
    $document = Document::factory()->purchaseInvoice()->create(['party_id' => null]);

    $this->resolver->resolve(
        $document,
        extractedInvoice('Brand New Supplier Ltd', '9999999999')
    );

    $document->refresh();
    expect($document->party_id)->not->toBeNull();

    $pending = Party::find($document->party_id);
    expect($pending->status)->toBe('pending')
        ->and($pending->business->legal_name)->toBe('Brand New Supplier Ltd')
        ->and($pending->business->tax_number)->toBe('9999999999');
});

it('creates a pending supplier with unknown name when no data is available', function (): void {
    $document = Document::factory()->purchaseInvoice()->create(['party_id' => null]);

    $this->resolver->resolve($document, extractedInvoice());

    $document->refresh();
    $pending = Party::find($document->party_id);
    expect($pending->status)->toBe('pending')
        ->and($pending->business->legal_name)->toBe('Unknown Supplier');
});
