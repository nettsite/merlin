<?php

use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\PartyService;
use App\Modules\Purchasing\DTO\ExtractedInvoice;
use App\Modules\Purchasing\Services\SupplierResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

function fakeJevChoice(string $choice, float $confidence): void
{
    Http::fake([
        'api.typesafe.ai/*' => Http::response([
            'model' => 'jev-1.13.0',
            'answers' => [
                'pick' => [
                    'type' => 'choice',
                    'choice' => $choice,
                    'probabilities' => [$choice => $confidence],
                    'confidence' => $confidence,
                ],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);
}

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

it('does not match a different party when another business shares the name', function (): void {
    // Guards the orWhere inside the whereHas callback: Laravel groups callback
    // constraints via callScope, so the legal_name OR must not escape the
    // relation key constraint and match an unrelated party.
    $decoy = $this->service->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Decoy Ltd',
    ], relationships: ['supplier']);

    $target = $this->service->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Target Corp',
    ], relationships: ['supplier']);

    $document = Document::factory()->purchaseInvoice()->create(['party_id' => null]);

    $this->resolver->resolve($document, extractedInvoice('Target Corp'));

    expect($document->fresh()->party_id)->toBe($target->id)
        ->and($document->fresh()->party_id)->not->toBe($decoy->id);
});

it('matches supplier via Jev choice when exact name match fails and confidence is high', function (): void {
    $supplier = $this->service->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Acme Corporation Pty Ltd',
        'trading_name' => 'Acme Corp',
    ], relationships: ['supplier']);

    fakeJevChoice((string) $supplier->id, 0.92);

    $document = Document::factory()->purchaseInvoice()->create(['party_id' => null]);

    $this->resolver->resolve($document, extractedInvoice('Acme Corp (Pty) Ltd t/a Acme'));

    expect($document->fresh()->party_id)->toBe($supplier->id);
});

it('falls through to a pending supplier when Jev confidence is below threshold', function (): void {
    $supplier = $this->service->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Acme Corporation Pty Ltd',
        'trading_name' => 'Acme Corp',
    ], relationships: ['supplier']);

    fakeJevChoice((string) $supplier->id, 0.4);

    $document = Document::factory()->purchaseInvoice()->create(['party_id' => null]);

    $this->resolver->resolve($document, extractedInvoice('Acme Corp (Pty) Ltd t/a Acme'));

    $document->refresh();
    expect($document->party_id)->not->toBe($supplier->id);
    expect(Party::find($document->party_id)->status)->toBe('pending');
});

it('falls through to a pending supplier when Jev picks none', function (): void {
    $supplier = $this->service->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Acme Corporation Pty Ltd',
        'trading_name' => 'Acme Corp',
    ], relationships: ['supplier']);

    fakeJevChoice('none', 0.95);

    $document = Document::factory()->purchaseInvoice()->create(['party_id' => null]);

    $this->resolver->resolve($document, extractedInvoice('Totally Unrelated Supplier'));

    $document->refresh();
    expect($document->party_id)->not->toBe($supplier->id);
    expect(Party::find($document->party_id)->status)->toBe('pending');
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
