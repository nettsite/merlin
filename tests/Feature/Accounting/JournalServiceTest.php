<?php

use App\Exceptions\PostedDocumentImmutableException;
use App\Exceptions\UnbalancedJournalEntryException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalService;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
    $this->service = app(JournalService::class);

    $this->ar = Account::factory()->create(['code' => '1100', 'allow_direct_posting' => true]);
    $this->income = Account::factory()->create(['code' => '4000', 'allow_direct_posting' => true]);
});

function journalDocument(): Document
{
    return Document::create([
        'document_type' => 'sales_invoice',
        'direction' => 'outbound',
        'status' => 'sent',
        'issue_date' => now()->toDateString(),
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'total' => 1000.00,
        'source' => 'manual',
    ]);
}

it('posts a balanced entry', function (): void {
    $doc = journalDocument();

    $entry = $this->service->post(
        source: 'sales_invoice_issued',
        date: now(),
        description: 'Test posting',
        lines: [
            ['account_id' => $this->ar->id, 'debit' => 1000.00],
            ['account_id' => $this->income->id, 'credit' => 1000.00],
        ],
        document: $doc,
    );

    expect($entry)->toBeInstanceOf(JournalEntry::class)
        ->and($entry->lines()->count())->toBe(2)
        ->and((float) $entry->lines()->sum('debit'))->toBe(1000.00)
        ->and((float) $entry->lines()->sum('credit'))->toBe(1000.00);
});

it('rejects an unbalanced entry', function (): void {
    $doc = journalDocument();

    expect(fn () => $this->service->post(
        source: 'sales_invoice_issued',
        date: now(),
        description: 'Unbalanced posting',
        lines: [
            ['account_id' => $this->ar->id, 'debit' => 1000.00],
            ['account_id' => $this->income->id, 'credit' => 900.00],
        ],
        document: $doc,
    ))->toThrow(UnbalancedJournalEntryException::class);

    expect(JournalEntry::count())->toBe(0);
});

it('is idempotent for the same document and source', function (): void {
    $doc = journalDocument();

    $lines = [
        ['account_id' => $this->ar->id, 'debit' => 1000.00],
        ['account_id' => $this->income->id, 'credit' => 1000.00],
    ];

    $first = $this->service->post('sales_invoice_issued', now(), 'First post', $lines, $doc);
    $second = $this->service->post('sales_invoice_issued', now(), 'Second post attempt', $lines, $doc);

    expect($second->id)->toBe($first->id)
        ->and(JournalEntry::count())->toBe(1);
});

it('reverses an entry with mirrored debits and credits', function (): void {
    $doc = journalDocument();

    $entry = $this->service->post(
        'sales_invoice_issued',
        now(),
        'Original posting',
        [
            ['account_id' => $this->ar->id, 'debit' => 1000.00],
            ['account_id' => $this->income->id, 'credit' => 1000.00],
        ],
        $doc,
    );

    $reversal = $this->service->reverse($entry, 'Invoice voided');

    expect($entry->fresh()->reversed_by_id)->toBe($reversal->id)
        ->and((float) $reversal->lines()->where('account_id', $this->ar->id)->value('credit'))->toBe(1000.00)
        ->and((float) $reversal->lines()->where('account_id', $this->income->id)->value('debit'))->toBe(1000.00);
});

it('reversing an already-reversed entry is a no-op', function (): void {
    $doc = journalDocument();

    $entry = $this->service->post(
        'sales_invoice_issued',
        now(),
        'Original posting',
        [
            ['account_id' => $this->ar->id, 'debit' => 1000.00],
            ['account_id' => $this->income->id, 'credit' => 1000.00],
        ],
        $doc,
    );

    $first = $this->service->reverse($entry, 'First reversal');
    $second = $this->service->reverse($entry->fresh(), 'Second reversal attempt');

    expect($second->id)->toBe($first->id)
        ->and(JournalEntry::count())->toBe(2);
});

it('refuses to save a line on a document with a posted journal entry', function (): void {
    $doc = journalDocument();

    $line = $doc->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Original',
        'account_id' => $this->income->id,
        'quantity' => 1,
        'unit_price' => 1000.00,
        'tax_rate' => null,
    ]);

    $this->service->post(
        'sales_invoice_issued',
        now(),
        'Posted',
        [
            ['account_id' => $this->ar->id, 'debit' => 1000.00],
            ['account_id' => $this->income->id, 'credit' => 1000.00],
        ],
        $doc,
    );

    $line->description = 'Edited after posting';

    expect(fn () => $line->save())
        ->toThrow(PostedDocumentImmutableException::class);
});

it('refuses to delete a line on a document with a posted journal entry', function (): void {
    $doc = journalDocument();

    $line = $doc->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Original',
        'account_id' => $this->income->id,
        'quantity' => 1,
        'unit_price' => 1000.00,
        'tax_rate' => null,
    ]);

    $this->service->post(
        'sales_invoice_issued',
        now(),
        'Posted',
        [
            ['account_id' => $this->ar->id, 'debit' => 1000.00],
            ['account_id' => $this->income->id, 'credit' => 1000.00],
        ],
        $doc,
    );

    expect(fn () => $line->delete())
        ->toThrow(PostedDocumentImmutableException::class);
});

it('allows a saveQuietly() line edit on a posted document (FX finalisation, VAT correction)', function (): void {
    $doc = journalDocument();

    $line = $doc->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Original',
        'account_id' => $this->income->id,
        'quantity' => 1,
        'unit_price' => 1000.00,
        'tax_rate' => null,
    ]);

    $this->service->post(
        'sales_invoice_issued',
        now(),
        'Posted',
        [
            ['account_id' => $this->ar->id, 'debit' => 1000.00],
            ['account_id' => $this->income->id, 'credit' => 1000.00],
        ],
        $doc,
    );

    $line->description = 'Corrected via trusted system path';
    $line->saveQuietly();

    expect($line->fresh()->description)->toBe('Corrected via trusted system path');
});
