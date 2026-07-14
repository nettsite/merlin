<?php

use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('assigns sequential numbers to documents of the same type', function (): void {
    $first = Document::factory()->purchaseInvoice()->create();
    $second = Document::factory()->purchaseInvoice()->create();

    $firstSeq = (int) substr($first->document_number, strrpos($first->document_number, '-') + 1);
    $secondSeq = (int) substr($second->document_number, strrpos($second->document_number, '-') + 1);

    expect($secondSeq)->toBe($firstSeq + 1);
});

it('does not reuse a number orphaned by reclassifying a document to a different type', function (): void {
    // Reproduces the InvoiceProcessingService flow: a document is created as
    // purchase_invoice (auto-numbered PINV-*), then reclassified to
    // payment_notification once its content is inspected — the row keeps its
    // PINV-* number even though document_type no longer matches. A later
    // purchase_invoice must not compute that same orphaned number.
    $original = Document::factory()->purchaseInvoice()->create();

    $reclassified = Document::factory()->purchaseInvoice()->create();
    $reclassified->update(['document_type' => 'payment_notification']);

    $next = Document::factory()->purchaseInvoice()->create();

    expect($next->document_number)->not->toBe($reclassified->document_number)
        ->and($next->document_number)->not->toBe($original->document_number);
});
