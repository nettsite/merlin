<?php

use App\Exceptions\PostedDocumentImmutableException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());

    $this->ar = Account::factory()->create(['code' => '1100', 'allow_direct_posting' => true]);
    $this->income = Account::factory()->create(['code' => '4000', 'allow_direct_posting' => true]);
});

function immutabilityInvoice(Account $ar, Account $income): Document
{
    $doc = Document::create([
        'document_type' => 'sales_invoice',
        'direction' => 'outbound',
        'status' => 'draft',
        'issue_date' => now()->toDateString(),
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'total' => 1000.00,
        'source' => 'manual',
        'receivable_account_id' => $ar->id,
    ]);

    $doc->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Original',
        'account_id' => $income->id,
        'quantity' => 1,
        'unit_price' => 1000.00,
        'tax_rate' => null,
    ]);

    return $doc->fresh();
}

it('allows editing a line on a draft invoice', function (): void {
    $doc = immutabilityInvoice($this->ar, $this->income);
    $line = $doc->lines->first();

    $line->description = 'Edited before issuing';
    $line->save();

    expect($line->fresh()->description)->toBe('Edited before issuing');
});

it('refuses to save a line once the invoice is issued', function (): void {
    $doc = immutabilityInvoice($this->ar, $this->income);
    $line = $doc->lines->first();

    app(DocumentService::class)->markAsSent($doc, User::factory()->create());

    $line->description = 'Edited after issuing';

    expect(fn () => $line->save())
        ->toThrow(PostedDocumentImmutableException::class);
});

it('refuses to delete a line once the invoice is issued', function (): void {
    $doc = immutabilityInvoice($this->ar, $this->income);
    $line = $doc->lines->first();

    app(DocumentService::class)->markAsSent($doc, User::factory()->create());

    expect(fn () => $line->delete())
        ->toThrow(PostedDocumentImmutableException::class);
});

it('allows a saveQuietly() line edit on an issued document (FX finalisation, VAT correction)', function (): void {
    $doc = immutabilityInvoice($this->ar, $this->income);
    $line = $doc->lines->first();

    app(DocumentService::class)->markAsSent($doc, User::factory()->create());

    $line->description = 'Corrected via trusted system path';
    $line->saveQuietly();

    expect($line->fresh()->description)->toBe('Corrected via trusted system path');
});

it('locks a purchase invoice only once posted, not while under review', function (): void {
    $ap = Account::factory()->create(['allow_direct_posting' => true]);
    $expense = Account::factory()->create(['allow_direct_posting' => true]);

    $doc = Document::create([
        'document_type' => 'purchase_invoice',
        'direction' => 'inbound',
        'status' => 'received',
        'issue_date' => now()->toDateString(),
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'total' => 500.00,
        'source' => 'manual',
        'payable_account_id' => $ap->id,
    ]);
    $line = $doc->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Original',
        'account_id' => $expense->id,
        'quantity' => 1,
        'unit_price' => 500.00,
        'tax_rate' => null,
    ]);

    // Still under review — the extraction/correction workflow needs this open.
    $line->description = 'Corrected during review';
    $line->save();
    expect($line->fresh()->description)->toBe('Corrected during review');

    app(DocumentService::class)->post($doc->fresh(), User::factory()->create());

    $line->description = 'Edited after posting';
    expect(fn () => $line->save())->toThrow(PostedDocumentImmutableException::class);
});

it('locks a credit note the moment it is issued, before it is applied', function (): void {
    $ar = $this->ar;
    $income = $this->income;
    $creditNote = Document::create([
        'document_type' => 'credit_note',
        'direction' => 'outbound',
        'status' => 'draft',
        'issue_date' => now()->toDateString(),
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'total' => 200.00,
        'source' => 'manual',
    ]);
    $line = $creditNote->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Goodwill credit',
        'account_id' => $income->id,
        'quantity' => 1,
        'unit_price' => 200.00,
        'tax_rate' => null,
    ]);

    app(DocumentService::class)->issueCreditNote($creditNote->fresh(), User::factory()->create());

    // Locked at 'issued' — before it has ever been applied to an invoice,
    // matching "a document is immutable once issued" rather than waiting
    // for its GL effect.
    $line->description = 'Edited after issuing';
    expect(fn () => $line->save())->toThrow(PostedDocumentImmutableException::class);
});
