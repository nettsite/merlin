<?php

use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Models\DocumentLine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- isForeignCurrency accessor ---

it('identifies a ZAR invoice as not foreign currency', function (): void {
    $doc = Document::factory()->purchaseInvoice()->create(['currency' => config('currency.base', 'ZAR')]);

    expect($doc->is_foreign_currency)->toBeFalse();
});

it('identifies a USD invoice as foreign currency', function (): void {
    $doc = Document::factory()->purchaseInvoice()->create(['currency' => 'USD']);

    expect($doc->is_foreign_currency)->toBeTrue();
});

// --- recalculateTotals — ZAR invoice ---

it('leaves foreign columns null for a ZAR invoice', function (): void {
    $doc = Document::factory()->purchaseInvoice()->create([
        'currency' => config('currency.base', 'ZAR'),
        'exchange_rate' => '1.000000',
    ]);

    DocumentLine::factory()->for($doc)->create([
        'quantity' => 1,
        'unit_price' => 100.00,
        'tax_rate' => 15.00,
    ]);

    $doc->refresh();

    expect($doc->foreign_subtotal)->toBeNull()
        ->and($doc->foreign_tax_total)->toBeNull()
        ->and($doc->foreign_total)->toBeNull()
        ->and($doc->foreign_balance_due)->toBeNull();
});

it('calculates correct base totals from line amounts for a ZAR invoice', function (): void {
    $doc = Document::factory()->purchaseInvoice()->create(['currency' => config('currency.base', 'ZAR')]);

    DocumentLine::factory()->for($doc)->create([
        'quantity' => 2,
        'unit_price' => 500.00,
        'tax_rate' => 15.00,
    ]);

    $doc->refresh();

    // 2 × 500 = 1000 subtotal; 15% tax = 150; total = 1150
    expect((float) $doc->subtotal)->toBe(1000.0)
        ->and((float) $doc->tax_total)->toBe(150.0)
        ->and((float) $doc->total)->toBe(1150.0)
        ->and((float) $doc->balance_due)->toBe(1150.0);
});

// --- recalculateTotals — foreign currency invoice ---

it('calculates base totals from foreign amounts and exchange rate', function (): void {
    $rate = 18.35;

    $doc = Document::factory()->purchaseInvoice()->create([
        'currency' => 'USD',
        'exchange_rate' => $rate,
        'amount_paid' => 0,
    ]);

    // Unit price is in ZAR (base); foreign amounts stored explicitly
    DocumentLine::factory()->for($doc)->create([
        'quantity' => 1,
        'unit_price' => round(100.0 * $rate, 4),   // ZAR
        'foreign_unit_price' => 100.0,
        'foreign_line_total' => 100.0,
        'tax_rate' => 15.00,
    ]);

    $doc->refresh();

    expect((float) $doc->subtotal)->toBe(round(100.0 * $rate, 2))
        ->and($doc->foreign_subtotal)->not->toBeNull()
        ->and((float) $doc->foreign_subtotal)->toBe(100.0);
});

it('populates foreign_balance_due on a foreign currency invoice', function (): void {
    $doc = Document::factory()->purchaseInvoice()->create([
        'currency' => 'USD',
        'exchange_rate' => 18.35,
        'amount_paid' => 0,
        'foreign_amount_paid' => null,
    ]);

    DocumentLine::factory()->for($doc)->create([
        'quantity' => 1,
        'unit_price' => round(200.0 * 18.35, 4),
        'foreign_unit_price' => 200.0,
        'foreign_line_total' => 200.0,
        'tax_rate' => null,
    ]);

    $doc->refresh();

    expect((float) $doc->foreign_total)->toBe(200.0)
        ->and((float) $doc->foreign_balance_due)->toBe(200.0);
});

// --- DocumentLine foreign columns ---

it('stores foreign line amounts independently of calculated base amounts', function (): void {
    $doc = Document::factory()->purchaseInvoice()->create([
        'currency' => 'USD',
        'exchange_rate' => 18.35,
    ]);

    $line = DocumentLine::factory()->for($doc)->create([
        'quantity' => 1,
        'unit_price' => round(50.0 * 18.35, 4),
        'foreign_unit_price' => 50.0,
        'foreign_line_total' => 50.0,
        'foreign_tax_amount' => 7.5,
        'tax_rate' => 15.00,
    ]);

    expect((float) $line->foreign_unit_price)->toBe(50.0)
        ->and((float) $line->foreign_line_total)->toBe(50.0)
        ->and((float) $line->foreign_tax_amount)->toBe(7.5);
});
