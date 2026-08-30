<?php

use App\Exceptions\InvalidDocumentStateException;
use App\Exceptions\PostedDocumentImmutableException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentRelationship;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentService;
use App\Modules\Core\Services\PartyService;
use Livewire\Livewire;

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

function makeSentInvoice(?Party $client = null, ?string $issueDate = null): Document
{
    $client ??= quoteClient();

    return Document::create([
        'document_type' => 'sales_invoice',
        'direction' => 'outbound',
        'status' => 'sent',
        'party_id' => $client->id,
        'issue_date' => $issueDate ?? now()->toDateString(),
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

it('does not reverse an applied credit when a payment is recorded', function (): void {
    $client = quoteClient();
    $invoice = makeSentInvoice($client); // total 1150, balance_due 1150
    $user = User::factory()->create();

    $cn = makeDraftCreditNote($client, 400.00);
    $cn->update(['status' => 'issued']);

    $svc = app(DocumentService::class);
    $svc->applyCreditNote($cn->fresh(), $invoice, $user);

    expect((float) $invoice->fresh()->balance_due)->toBe(750.00);

    $svc->recordPayment($invoice->fresh(), 750.00, now());

    $after = $invoice->fresh();
    expect((float) $after->balance_due)->toBe(0.0)
        ->and($after->status)->toBe('paid')
        ->and((float) $after->amount_paid)->toBe(750.00)
        ->and((float) $after->credits_applied)->toBe(400.00);
});

it('cannot edit a line after the invoice is sent, so an applied credit can never be silently reversed', function (): void {
    $client = quoteClient();
    $user = User::factory()->create();

    // Built and lined while still draft — a line can no longer be created
    // or edited on a sent invoice at all, so this has to happen first.
    $invoice = Document::create([
        'document_type' => 'sales_invoice',
        'direction' => 'outbound',
        'status' => 'draft',
        'party_id' => $client->id,
        'issue_date' => now()->toDateString(),
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'source' => 'manual',
    ]);
    $line = $invoice->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Annual retainer',
        'quantity' => 1,
        'unit_price' => 1150.00,
        'tax_rate' => null,
    ]);
    app(DocumentService::class)->markAsSent($invoice->fresh(), $user);

    $cn = makeDraftCreditNote($client, 400.00);
    $cn->update(['status' => 'issued']);

    app(DocumentService::class)->applyCreditNote($cn->fresh(), $invoice->fresh(), $user);

    expect((float) $invoice->fresh()->balance_due)->toBe(750.00);

    // The bug this test used to guard against — a normal line save's
    // recalculateTotals() silently wiping out the applied credit — can no
    // longer happen at all: the line is immutable the moment the invoice
    // is issued, so the edit itself is refused before it can touch totals.
    $line->description = 'Annual retainer (updated)';
    expect(fn () => $line->save())->toThrow(PostedDocumentImmutableException::class);

    expect((float) $invoice->fresh()->balance_due)->toBe(750.00)
        ->and((float) $invoice->fresh()->credits_applied)->toBe(400.00);
});

it('rejects a payment that exceeds the credited balance', function (): void {
    $client = quoteClient();
    $invoice = makeSentInvoice($client); // total 1150
    $user = User::factory()->create();

    $cn = makeDraftCreditNote($client, 400.00);
    $cn->update(['status' => 'issued']);

    app(DocumentService::class)->applyCreditNote($cn->fresh(), $invoice, $user);

    expect(fn () => app(DocumentService::class)->recordPayment($invoice->fresh(), 800.00, now()))
        ->toThrow(InvalidArgumentException::class);
});

// ── Credit notes as the sole reversal (never void) ─────────────────────────────

it('creates a draft credit note pre-filled from the invoice, full or partial', function (): void {
    $client = quoteClient();
    $invoice = Document::create([
        'document_type' => 'sales_invoice',
        'direction' => 'outbound',
        'status' => 'draft',
        'party_id' => $client->id,
        'issue_date' => '2026-03-15',
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'source' => 'manual',
    ]);
    $income = Account::factory()->create(['allow_direct_posting' => true]);
    $invoice->lines()->createMany([
        ['line_number' => 1, 'type' => 'service', 'description' => 'Design', 'account_id' => $income->id, 'quantity' => 1, 'unit_price' => 700.00, 'tax_rate' => null],
        ['line_number' => 2, 'type' => 'service', 'description' => 'Build', 'account_id' => $income->id, 'quantity' => 1, 'unit_price' => 300.00, 'tax_rate' => null],
    ]);
    $invoice->recalculateTotals();
    $user = User::factory()->create();
    app(DocumentService::class)->markAsSent($invoice->fresh(), $user);

    $creditNote = app(DocumentService::class)->createCreditNoteFromInvoice($invoice->fresh(), $user);

    expect($creditNote->status)->toBe('draft')
        ->and($creditNote->party_id)->toBe($client->id)
        ->and((float) $creditNote->total)->toBe(1000.0)
        ->and($creditNote->lines)->toHaveCount(2);

    // The invoice itself is untouched — the draft can be trimmed for a
    // partial credit without ever mutating the original.
    expect((float) $invoice->fresh()->total)->toBe(1000.0);

    $creditNote->lines()->where('line_number', 2)->delete();
    $creditNote->recalculateTotals();
    expect((float) $creditNote->fresh()->total)->toBe(700.0);
});

it('rejects a credit note dated before the invoice it credits', function (): void {
    $client = quoteClient();
    $invoice = makeSentInvoice($client, '2026-03-15');
    $user = User::factory()->create();

    $creditNote = makeDraftCreditNote($client, 200.00);
    $creditNote->update(['issue_date' => '2026-02-01', 'status' => 'issued']);

    expect(fn () => app(DocumentService::class)->applyCreditNote($creditNote->fresh(), $invoice->fresh(), $user))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps March revenue in March when the invoice is credited in July', function (): void {
    $client = quoteClient();
    $income = Account::factory()->create(['allow_direct_posting' => true]);
    $ar = Account::factory()->create(['allow_direct_posting' => true]);
    $user = User::factory()->create();

    $invoice = Document::create([
        'document_type' => 'sales_invoice',
        'direction' => 'outbound',
        'status' => 'draft',
        'party_id' => $client->id,
        'issue_date' => '2026-03-15',
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'source' => 'manual',
        'receivable_account_id' => $ar->id,
    ]);
    $invoice->lines()->create([
        'line_number' => 1, 'type' => 'service', 'description' => 'Consulting',
        'account_id' => $income->id, 'quantity' => 1, 'unit_price' => 1000.00, 'tax_rate' => null,
    ]);
    $invoice->recalculateTotals();
    app(DocumentService::class)->markAsSent($invoice->fresh(), $user);

    $creditNote = app(DocumentService::class)->createCreditNoteFromInvoice($invoice->fresh(), $user);
    $creditNote->update(['issue_date' => '2026-07-10']);
    app(DocumentService::class)->issueCreditNote($creditNote->fresh(), $user);
    app(DocumentService::class)->applyCreditNote($creditNote->fresh(), $invoice->fresh(), $user);

    $this->actingAs($user);

    // March: the invoice's revenue is there, exactly as filed.
    $march = Livewire::test('pages.reports.trial-balance')
        ->set('dateFrom', '2026-03-01')->set('dateTo', '2026-03-31');
    $marchIncome = $march->viewData('rows')->flatten()->firstWhere('id', $income->id);
    expect((float) $marchIncome->mov_credit)->toBe(1000.0);

    // Cumulative balance as at end of March: revenue still stands.
    $marchBalance = Livewire::test('pages.reports.trial-balance')->set('dateTo', '2026-03-31');
    $marchBalanceRow = $marchBalance->viewData('rows')->flatten()->firstWhere('id', $income->id);
    expect((float) $marchBalanceRow->bal_credit)->toBe(1000.0);

    // July: the credit note's reversal shows up here, not in March.
    $july = Livewire::test('pages.reports.trial-balance')
        ->set('dateFrom', '2026-07-01')->set('dateTo', '2026-07-31');
    $julyIncome = $july->viewData('rows')->flatten()->firstWhere('id', $income->id);
    expect((float) $julyIncome->mov_debit)->toBe(1000.0);

    // Cumulative balance as at end of July: fully reversed.
    $julyBalance = Livewire::test('pages.reports.trial-balance')->set('dateTo', '2026-07-31');
    $julyBalanceRow = $julyBalance->viewData('rows')->flatten()->firstWhere('id', $income->id);
    expect((float) $julyBalanceRow->bal_credit)->toBe(0.0)
        ->and((float) $julyBalanceRow->bal_debit)->toBe(0.0);

    expect($invoice->fresh()->status)->toBe('paid')
        ->and($invoice->fresh()->is_fully_credited)->toBeTrue();
});
