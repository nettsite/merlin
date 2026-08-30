<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentLine;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
    Cache::forget('documents:verify-audit-trail:last-run');
});

it('passes when every recent change has a matching activity entry', function (): void {
    Document::create([
        'document_type' => 'sales_invoice',
        'direction' => 'outbound',
        'status' => 'draft',
        'party_id' => Party::factory()->create()->id,
        'issue_date' => now()->toDateString(),
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'total' => 1000.00,
        'source' => 'manual',
    ]);

    $this->artisan('documents:verify-audit-trail')->assertExitCode(0);
});

it('flags a document changed with logging disabled as an orphan', function (): void {
    $doc = Document::create([
        'document_type' => 'sales_invoice',
        'direction' => 'outbound',
        'status' => 'draft',
        'party_id' => Party::factory()->create()->id,
        'issue_date' => now()->toDateString(),
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'total' => 1000.00,
        'source' => 'manual',
    ]);

    $this->travel(5)->minutes();

    $doc->notes = 'Changed outside the audit trail';
    $doc->saveQuietly();

    $this->artisan('documents:verify-audit-trail')->assertExitCode(1);
});

it('flags a document line changed with logging disabled as an orphan', function (): void {
    $ar = Account::factory()->create(['allow_direct_posting' => true]);
    $income = Account::factory()->create(['allow_direct_posting' => true]);

    $doc = Document::create([
        'document_type' => 'sales_invoice',
        'direction' => 'outbound',
        'status' => 'draft',
        'party_id' => Party::factory()->create()->id,
        'issue_date' => now()->toDateString(),
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'total' => 1000.00,
        'source' => 'manual',
        'receivable_account_id' => $ar->id,
    ]);

    $line = $doc->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Original',
        'account_id' => $income->id,
        'quantity' => 1,
        'unit_price' => 1000.00,
        'tax_rate' => null,
    ]);

    $this->travel(5)->minutes();

    DocumentLine::$recalculatesDocumentTotals = false;
    $line->description = 'Tampered';
    $line->saveQuietly();
    DocumentLine::$recalculatesDocumentTotals = true;

    $this->artisan('documents:verify-audit-trail')->assertExitCode(1);
});
