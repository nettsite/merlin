<?php

use App\Exceptions\InvalidDocumentStateException;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\PartyService;
use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Models\DocumentRelationship;
use App\Modules\Purchasing\Services\DocumentService;

// ── Helpers ──────────────────────────────────────────────────────────────────

function quoteClient(): Party
{
    return app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Quote Client Ltd',
        'status' => 'active',
    ], ['client']);
}

function makeQuote(?Party $client = null): Document
{
    $client ??= quoteClient();

    return Document::create([
        'document_type' => 'quote',
        'direction' => 'outbound',
        'status' => 'draft',
        'party_id' => $client->id,
        'issue_date' => now()->toDateString(),
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'source' => 'manual',
    ]);
}

function makeQuoteWithLines(?Party $client = null): Document
{
    $quote = makeQuote($client);

    $quote->lines()->createMany([
        [
            'line_number' => 1,
            'type' => 'service',
            'description' => 'Consulting',
            'quantity' => 2,
            'unit_price' => 500.00,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'line_total' => 1000.00,
            'tax_rate' => 15,
            'tax_amount' => 150.00,
        ],
        [
            'line_number' => 2,
            'type' => 'service',
            'description' => 'Support',
            'quantity' => 1,
            'unit_price' => 200.00,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'line_total' => 200.00,
            'tax_rate' => 15,
            'tax_amount' => 30.00,
        ],
    ]);

    $quote->update(['subtotal' => 1200.00, 'tax_total' => 180.00, 'total' => 1380.00, 'balance_due' => 1380.00]);

    return $quote->fresh();
}

function makeSentInvoice(?Party $client = null): Document
{
    $client ??= quoteClient();

    return Document::create([
        'document_type' => 'sales_invoice',
        'direction' => 'outbound',
        'status' => 'sent',
        'party_id' => $client->id,
        'issue_date' => now()->toDateString(),
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'subtotal' => 1000.00,
        'tax_total' => 150.00,
        'total' => 1150.00,
        'balance_due' => 1150.00,
        'source' => 'manual',
    ]);
}

function makeDraftCreditNote(?Party $client = null, float $total = 300.00): Document
{
    $client ??= quoteClient();

    return Document::create([
        'document_type' => 'credit_note',
        'direction' => 'outbound',
        'status' => 'draft',
        'party_id' => $client->id,
        'issue_date' => now()->toDateString(),
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'total' => $total,
        'source' => 'manual',
    ]);
}

// ── Quote state machine ───────────────────────────────────────────────────────

it('quote can be sent from draft', function (): void {
    $quote = makeQuote();
    $user = User::factory()->create();

    app(DocumentService::class)->sendQuote($quote, $user);

    expect($quote->fresh()->status)->toBe('sent');
});

it('quote can be accepted from sent', function (): void {
    $quote = makeQuote();
    $user = User::factory()->create();

    app(DocumentService::class)->sendQuote($quote, $user);
    app(DocumentService::class)->acceptQuote($quote->fresh(), $user);

    expect($quote->fresh()->status)->toBe('accepted');
});

it('quote can be declined from sent', function (): void {
    $quote = makeQuote();
    $user = User::factory()->create();

    app(DocumentService::class)->sendQuote($quote, $user);
    app(DocumentService::class)->declineQuote($quote->fresh(), $user);

    expect($quote->fresh()->status)->toBe('declined');
});

it('quote can be expired from draft', function (): void {
    $quote = makeQuote();
    $user = User::factory()->create();

    app(DocumentService::class)->expireQuote($quote, $user);

    expect($quote->fresh()->status)->toBe('expired');
});

it('accepted quote cannot be accepted again', function (): void {
    $quote = makeQuote();
    $user = User::factory()->create();

    app(DocumentService::class)->sendQuote($quote, $user);
    app(DocumentService::class)->acceptQuote($quote->fresh(), $user);

    expect(fn () => app(DocumentService::class)->acceptQuote($quote->fresh(), $user))
        ->toThrow(InvalidDocumentStateException::class);
});

// ── Convert quote to invoice ──────────────────────────────────────────────────

it('convert quote to invoice creates sales invoice with matching lines', function (): void {
    $client = quoteClient();
    $quote = makeQuoteWithLines($client);
    $user = User::factory()->create();

    app(DocumentService::class)->sendQuote($quote, $user);
    app(DocumentService::class)->acceptQuote($quote->fresh(), $user);

    $invoice = app(DocumentService::class)->convertQuoteToInvoice($quote->fresh(), $user);

    expect($invoice->document_type)->toBe('sales_invoice')
        ->and($invoice->status)->toBe('draft')
        ->and($invoice->party_id)->toBe($client->id)
        ->and($invoice->lines()->count())->toBe(2);

    $invoiceDescriptions = $invoice->lines()->pluck('description')->sort()->values()->toArray();
    $quoteDescriptions = $quote->lines()->pluck('description')->sort()->values()->toArray();
    expect($invoiceDescriptions)->toBe($quoteDescriptions);
});

it('converted quote gets converted status', function (): void {
    $quote = makeQuoteWithLines();
    $user = User::factory()->create();

    app(DocumentService::class)->sendQuote($quote, $user);
    app(DocumentService::class)->acceptQuote($quote->fresh(), $user);
    app(DocumentService::class)->convertQuoteToInvoice($quote->fresh(), $user);

    expect($quote->fresh()->status)->toBe('converted');
});

it('convert links quote and invoice via converted_from relationship', function (): void {
    $quote = makeQuoteWithLines();
    $user = User::factory()->create();

    app(DocumentService::class)->sendQuote($quote, $user);
    app(DocumentService::class)->acceptQuote($quote->fresh(), $user);
    $invoice = app(DocumentService::class)->convertQuoteToInvoice($quote->fresh(), $user);

    $link = DocumentRelationship::where('parent_document_id', $quote->id)
        ->where('child_document_id', $invoice->id)
        ->where('relationship_type', 'converted_from')
        ->first();

    expect($link)->not->toBeNull();
});

it('quote numbering uses QUO prefix', function (): void {
    $quote = makeQuote();
    expect($quote->document_number)->toStartWith('QUO-');
});

// ── Credit note state machine ─────────────────────────────────────────────────

it('credit note can be issued from draft', function (): void {
    $user = User::factory()->create();
    $cn = makeDraftCreditNote();

    app(DocumentService::class)->issueCreditNote($cn, $user);

    expect($cn->fresh()->status)->toBe('issued');
});

it('credit note reduces target invoice balance_due', function (): void {
    $client = quoteClient();
    $invoice = makeSentInvoice($client);
    $user = User::factory()->create();

    $cn = makeDraftCreditNote($client, 300.00);
    $cn->update(['status' => 'issued']);

    app(DocumentService::class)->applyCreditNote($cn->fresh(), $invoice, $user);

    expect((float) $invoice->fresh()->balance_due)->toBe(850.00)
        ->and($cn->fresh()->status)->toBe('applied');
});

it('credit note application links documents via credited_by relationship', function (): void {
    $client = quoteClient();
    $invoice = makeSentInvoice($client);
    $user = User::factory()->create();

    $cn = makeDraftCreditNote($client, 100.00);
    $cn->update(['status' => 'issued']);

    app(DocumentService::class)->applyCreditNote($cn->fresh(), $invoice, $user);

    $link = DocumentRelationship::where('parent_document_id', $invoice->id)
        ->where('child_document_id', $cn->id)
        ->where('relationship_type', 'credited_by')
        ->first();

    expect($link)->not->toBeNull();
});

it('credit note balance_due floors at zero', function (): void {
    $client = quoteClient();
    $invoice = makeSentInvoice($client); // balance_due = 1150
    $user = User::factory()->create();

    $cn = makeDraftCreditNote($client, 9999.00);
    $cn->update(['status' => 'issued']);

    app(DocumentService::class)->applyCreditNote($cn->fresh(), $invoice, $user);

    expect((float) $invoice->fresh()->balance_due)->toBe(0.0);
});

it('credit note numbering uses CRN prefix', function (): void {
    $cn = makeDraftCreditNote();
    expect($cn->document_number)->toStartWith('CRN-');
});
