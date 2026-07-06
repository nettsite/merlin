<?php

use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentActivity;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Purchasing\Services\DuplicateInvoiceMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
    $this->merger = new DuplicateInvoiceMerger;
});

it('finds an existing invoice with the same supplier and reference', function (): void {
    $party = Party::factory()->create();

    $original = Document::factory()->purchaseInvoice()->create([
        'party_id' => $party->id,
        'reference' => '1680798',
    ]);

    $resend = Document::factory()->purchaseInvoice()->create([
        'party_id' => $party->id,
        'reference' => '1680798',
    ]);

    $found = $this->merger->findDuplicate($resend);

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($original->id);
});

it('does not match invoices from a different supplier with the same reference number', function (): void {
    Document::factory()->purchaseInvoice()->create([
        'party_id' => Party::factory()->create()->id,
        'reference' => '1680798',
    ]);

    $resend = Document::factory()->purchaseInvoice()->create([
        'party_id' => Party::factory()->create()->id,
        'reference' => '1680798',
    ]);

    expect($this->merger->findDuplicate($resend))->toBeNull();
});

it('merges a duplicate invoice by attaching its media to the original and discarding the duplicate row', function (): void {
    $party = Party::factory()->create();

    $original = Document::factory()->purchaseInvoice()->create([
        'party_id' => $party->id,
        'reference' => '1680798',
    ]);

    $duplicate = Document::factory()->purchaseInvoice()->create([
        'party_id' => $party->id,
        'reference' => '1680798',
    ]);

    $this->merger->merge($duplicate, $original);

    expect(Document::find($duplicate->id))->toBeNull()
        ->and(DocumentActivity::where('document_id', $original->id)
            ->where('activity_type', 'duplicate_invoice_attached')
            ->exists())->toBeTrue();
});
