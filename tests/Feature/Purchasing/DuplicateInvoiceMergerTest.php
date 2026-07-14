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

// --- Fuzzy matching (receipts with their own number) ---

it('finds a fuzzy duplicate by party, amount, and date proximity when there is no reference match', function (): void {
    $party = Party::factory()->create();

    $original = Document::factory()->purchaseInvoice()->create([
        'party_id' => $party->id,
        'reference' => '1680798',
        'issue_date' => '2026-06-01',
        'total' => 1150.00,
    ]);

    // A receipt for the same purchase, but carrying its own receipt number.
    $receipt = Document::factory()->purchaseInvoice()->create([
        'party_id' => $party->id,
        'reference' => 'RCPT-99',
        'issue_date' => '2026-06-05',
    ]);

    $found = $this->merger->findFuzzyDuplicate($receipt, 1150.00);

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($original->id);
});

it('does not fuzzy-match invoices outside the amount tolerance', function (): void {
    $party = Party::factory()->create();

    Document::factory()->purchaseInvoice()->create([
        'party_id' => $party->id,
        'issue_date' => '2026-06-01',
        'total' => 1150.00,
    ]);

    $receipt = Document::factory()->purchaseInvoice()->create([
        'party_id' => $party->id,
        'issue_date' => '2026-06-05',
    ]);

    expect($this->merger->findFuzzyDuplicate($receipt, 2000.00))->toBeNull();
});

it('does not fuzzy-match invoices outside the date window', function (): void {
    $party = Party::factory()->create();

    Document::factory()->purchaseInvoice()->create([
        'party_id' => $party->id,
        'issue_date' => '2026-01-01',
        'total' => 1150.00,
    ]);

    $receipt = Document::factory()->purchaseInvoice()->create([
        'party_id' => $party->id,
        'issue_date' => '2026-06-05',
    ]);

    expect($this->merger->findFuzzyDuplicate($receipt, 1150.00))->toBeNull();
});
