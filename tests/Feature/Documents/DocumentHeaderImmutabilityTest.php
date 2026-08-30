<?php

use App\Exceptions\PostedDocumentImmutableException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentService;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

function immutableHeaderInvoice(string $status = 'draft'): Document
{
    return Document::create([
        'document_type' => 'sales_invoice',
        'direction' => 'outbound',
        'status' => $status,
        'party_id' => Party::factory()->create()->id,
        'issue_date' => '2026-03-01',
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'subtotal' => 1000.00,
        'tax_total' => 0,
        'total' => 1000.00,
        'source' => 'manual',
    ]);
}

it('allows editing commercial columns on a draft invoice', function (): void {
    $doc = immutableHeaderInvoice('draft');

    $doc->total = 1500.00;
    $doc->subtotal = 1500.00;
    $doc->issue_date = '2026-03-05';
    $doc->document_number = 'SINV-CUSTOM-01';
    $doc->save();

    expect((float) $doc->fresh()->total)->toBe(1500.0)
        ->and($doc->fresh()->document_number)->toBe('SINV-CUSTOM-01');
});

it('refuses to change total once the invoice is sent', function (): void {
    $doc = immutableHeaderInvoice('sent');

    $doc->total = 9999.00;
    expect(fn () => $doc->save())->toThrow(PostedDocumentImmutableException::class);
});

it('refuses to change issue_date once the invoice is sent', function (): void {
    $doc = immutableHeaderInvoice('sent');

    $doc->issue_date = '2026-04-01';
    expect(fn () => $doc->save())->toThrow(PostedDocumentImmutableException::class);
});

it('refuses to change party_id once the invoice is sent', function (): void {
    $doc = immutableHeaderInvoice('sent');

    $doc->party_id = Party::factory()->create()->id;
    expect(fn () => $doc->save())->toThrow(PostedDocumentImmutableException::class);
});

it('refuses to change document_number once the invoice is sent', function (): void {
    $doc = immutableHeaderInvoice('sent');

    $doc->document_number = 'SINV-RENUMBERED';
    expect(fn () => $doc->save())->toThrow(PostedDocumentImmutableException::class);
});

it('refuses to change subtotal or tax_total once the invoice is sent', function (): void {
    $doc = immutableHeaderInvoice('sent');

    $doc->subtotal = 2000.00;
    expect(fn () => $doc->save())->toThrow(PostedDocumentImmutableException::class);

    $doc = immutableHeaderInvoice('sent');
    $doc->tax_total = 150.00;
    expect(fn () => $doc->save())->toThrow(PostedDocumentImmutableException::class);
});

it('still allows unguarded columns (notes) to change once sent', function (): void {
    $doc = immutableHeaderInvoice('sent');

    $doc->notes = 'Updated note';
    $doc->save();

    expect($doc->fresh()->notes)->toBe('Updated note');
});

it('does not trip the guard when a draft is issued and its issue_date is confirmed in the same save', function (): void {
    // The transition into 'issued' must not itself freeze the columns —
    // only a save touching them on a document already issued before that
    // save started should.
    $doc = immutableHeaderInvoice('draft');

    $doc->status = 'sent';
    $doc->issue_date = '2026-03-10';
    $doc->save();

    expect($doc->fresh()->status)->toBe('sent')
        ->and($doc->fresh()->issue_date->toDateString())->toBe('2026-03-10');
});

it('allows a saveQuietly() rewrite of frozen columns on an issued invoice (FX finalisation)', function (): void {
    $doc = immutableHeaderInvoice('sent');

    $doc->total = 1234.56;
    $doc->saveQuietly();

    expect((float) $doc->fresh()->total)->toBe(1234.56);
});

it('freezes a purchase invoice only once posted, not while under review', function (): void {
    $ap = Account::factory()->create(['allow_direct_posting' => true]);

    $doc = Document::create([
        'document_type' => 'purchase_invoice',
        'direction' => 'inbound',
        'status' => 'received',
        'issue_date' => '2026-03-01',
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'total' => 500.00,
        'source' => 'manual',
        'payable_account_id' => $ap->id,
    ]);

    $doc->total = 600.00;
    $doc->save(); // still under review — allowed
    expect((float) $doc->fresh()->total)->toBe(600.0);

    app(DocumentService::class)->post($doc->fresh(), User::factory()->create());

    $posted = $doc->fresh();
    $posted->total = 700.00;
    expect(fn () => $posted->save())->toThrow(PostedDocumentImmutableException::class);
});

it('freezes a credit note the moment it is issued, before it is applied', function (): void {
    $doc = Document::create([
        'document_type' => 'credit_note',
        'direction' => 'outbound',
        'status' => 'draft',
        'party_id' => Party::factory()->create()->id,
        'issue_date' => '2026-03-01',
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'total' => 200.00,
        'source' => 'manual',
    ]);

    app(DocumentService::class)->issueCreditNote($doc->fresh(), User::factory()->create());

    $issued = $doc->fresh();
    $issued->total = 999.00;
    expect(fn () => $issued->save())->toThrow(PostedDocumentImmutableException::class);
});
