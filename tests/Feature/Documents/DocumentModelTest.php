<?php

use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Models\DocumentLine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- Document number generation ---

it('generates sequential document numbers from the documents table', function (): void {
    $first = Document::factory()->purchaseInvoice()->create();
    $second = Document::factory()->purchaseInvoice()->create();

    $year = now()->year;

    expect($first->document_number)->toBe("PINV-{$year}-00001")
        ->and($second->document_number)->toBe("PINV-{$year}-00002");
});

it('generates a unique number when a collision would occur', function (): void {
    $year = now()->year;

    // Pre-seed a document with a specific number to force the next lookup to increment past it
    Document::factory()->purchaseInvoice()->withNumber("PINV-{$year}-00001")->create();

    $next = Document::factory()->purchaseInvoice()->create();

    expect($next->document_number)->toBe("PINV-{$year}-00002");
});

it('sequences independently per document type', function (): void {
    $year = now()->year;

    $invoice = Document::factory()->purchaseInvoice()->create();
    $sales = Document::factory()->salesInvoice()->create();

    expect($invoice->document_number)->toBe("PINV-{$year}-00001")
        ->and($sales->document_number)->toBe("SINV-{$year}-00001");
});

// --- Line total calculations ---

it('calculates line total correctly with discount_amount', function (): void {
    $document = Document::factory()->create();

    $line = DocumentLine::factory()->create([
        'document_id' => $document->id,
        'quantity' => 2,
        'unit_price' => 100.00,
        'discount_amount' => 50.00,
        'discount_percent' => 10.00, // should be ignored when discount_amount is set
        'tax_rate' => 15.00,
    ]);

    // line_total = (2 * 100) - 50 = 150
    // tax_amount = 150 * 0.15 = 22.50
    expect((float) $line->line_total)->toBe(150.00)
        ->and((float) $line->tax_amount)->toBe(22.50);
});

it('calculates line total correctly with discount_percent', function (): void {
    $document = Document::factory()->create();

    $line = DocumentLine::factory()->create([
        'document_id' => $document->id,
        'quantity' => 4,
        'unit_price' => 50.00,
        'discount_percent' => 10.00,
        'discount_amount' => 0,
        'tax_rate' => 15.00,
    ]);

    // line_total = (4 * 50) - (200 * 0.10) = 200 - 20 = 180
    // tax_amount = 180 * 0.15 = 27.00
    expect((float) $line->line_total)->toBe(180.00)
        ->and((float) $line->tax_amount)->toBe(27.00);
});

it('applies no tax when tax_rate is null', function (): void {
    $document = Document::factory()->create();

    $line = DocumentLine::factory()->exempt()->create([
        'document_id' => $document->id,
        'quantity' => 1,
        'unit_price' => 200.00,
    ]);

    expect((float) $line->tax_amount)->toBe(0.0);
});

// --- Document total recalculation ---

it('recalculates document totals when a line is added', function (): void {
    $document = Document::factory()->create();

    DocumentLine::factory()->create([
        'document_id' => $document->id,
        'quantity' => 1,
        'unit_price' => 1000.00,
        'tax_rate' => 15.00,
    ]);

    $document->refresh();

    expect((float) $document->subtotal)->toBe(1000.00)
        ->and((float) $document->tax_total)->toBe(150.00)
        ->and((float) $document->total)->toBe(1150.00)
        ->and((float) $document->balance_due)->toBe(1150.00);
});

it('recalculates document totals when a line is deleted', function (): void {
    $document = Document::factory()->create();

    $line1 = DocumentLine::factory()->create([
        'document_id' => $document->id,
        'line_number' => 1,
        'quantity' => 1,
        'unit_price' => 500.00,
        'tax_rate' => 15.00,
    ]);

    DocumentLine::factory()->create([
        'document_id' => $document->id,
        'line_number' => 2,
        'quantity' => 1,
        'unit_price' => 500.00,
        'tax_rate' => 15.00,
    ]);

    $line1->delete();
    $document->refresh();

    expect((float) $document->subtotal)->toBe(500.00)
        ->and((float) $document->total)->toBe(575.00);
});
