<?php

use App\Exceptions\JournalNotBalancedException;
use App\Exceptions\PostedDocumentImmutableException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentRelationship;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentService;
use App\Modules\Purchasing\Settings\PurchasingSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
    $this->user = User::factory()->create();
    $this->service = app(DocumentService::class);
});

function journalDraft(): Document
{
    return Document::create([
        'document_type' => 'journal',
        'direction' => 'internal',
        'status' => 'draft',
        'issue_date' => '2026-03-01',
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'source' => 'manual',
    ]);
}

function journalLine(Document $journal, Account $account, float $amount, int $lineNumber = 1): void
{
    $journal->lines()->create([
        'line_number' => $lineNumber,
        'type' => 'description',
        'description' => 'Line',
        'account_id' => $account->id,
        'quantity' => 1,
        'unit_price' => $amount,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_rate' => null,
    ]);
}

it('rejects posting an unbalanced journal', function (): void {
    $journal = journalDraft();
    journalLine($journal, Account::factory()->expense()->create(), 500.00, 1);
    journalLine($journal, Account::factory()->asset()->create(), -400.00, 2);

    expect(fn () => $this->service->postJournal($journal->fresh(), $this->user))
        ->toThrow(JournalNotBalancedException::class);

    expect($journal->fresh()->status)->toBe('draft');
});

it('posts a balanced journal', function (): void {
    $journal = journalDraft();
    journalLine($journal, Account::factory()->expense()->create(), 500.00, 1);
    journalLine($journal, Account::factory()->asset()->create(), -500.00, 2);

    $this->service->postJournal($journal->fresh(), $this->user);

    expect($journal->fresh()->status)->toBe('posted')
        ->and((float) $journal->fresh()->total)->toBe(500.0);
});

it('rejects a journal line posting to the configured receivable control account', function (): void {
    $ar = Account::factory()->asset()->create(['code' => '1100']);
    $billingSettings = app(BillingSettings::class);
    $billingSettings->default_receivable_account_id = $ar->id;
    $billingSettings->save();

    $journal = journalDraft();
    journalLine($journal, $ar, 500.00, 1);
    journalLine($journal, Account::factory()->expense()->create(), -500.00, 2);

    // Re-resolve: the settings pointer changed after $this->service was
    // built in beforeEach, and Settings properties are captured at
    // construction time.
    expect(fn () => app(DocumentService::class)->postJournal($journal->fresh(), $this->user))
        ->toThrow(JournalNotBalancedException::class);
});

it('rejects a journal line posting to the configured payable control account', function (): void {
    $ap = Account::factory()->asset()->create(['code' => 'AP-CTRL']);
    $purchasingSettings = app(PurchasingSettings::class);
    $purchasingSettings->default_payable_account = $ap->code;
    $purchasingSettings->save();

    $journal = journalDraft();
    journalLine($journal, $ap, 500.00, 1);
    journalLine($journal, Account::factory()->expense()->create(), -500.00, 2);

    // Re-resolve: the settings pointer changed after $this->service was
    // built in beforeEach, and Settings properties are captured at
    // construction time.
    expect(fn () => app(DocumentService::class)->postJournal($journal->fresh(), $this->user))
        ->toThrow(JournalNotBalancedException::class);
});

it('freezes a posted journal header and lines', function (): void {
    $journal = journalDraft();
    journalLine($journal, Account::factory()->expense()->create(), 500.00, 1);
    journalLine($journal, Account::factory()->asset()->create(), -500.00, 2);
    $this->service->postJournal($journal->fresh(), $this->user);

    $posted = $journal->fresh();
    $posted->total = 999.00;
    expect(fn () => $posted->save())->toThrow(PostedDocumentImmutableException::class);

    $line = $posted->lines()->first();
    $line->unit_price = 1.00;
    expect(fn () => $line->save())->toThrow(PostedDocumentImmutableException::class);
});

it('reverses a posted journal with a dated, sign-flipped draft', function (): void {
    $expense = Account::factory()->expense()->create();
    $asset = Account::factory()->asset()->create();

    $journal = journalDraft();
    journalLine($journal, $expense, 500.00, 1);
    journalLine($journal, $asset, -500.00, 2);
    $this->service->postJournal($journal->fresh(), $this->user);

    $reversal = $this->service->createReversingJournal($journal->fresh(), $this->user, Carbon::parse('2026-07-01'));

    expect($reversal->status)->toBe('draft')
        ->and($reversal->issue_date->toDateString())->toBe('2026-07-01')
        ->and($reversal->lines)->toHaveCount(2);

    $reversalLines = $reversal->lines->keyBy('account_id');
    expect((float) $reversalLines[$expense->id]->unit_price)->toBe(-500.0)
        ->and((float) $reversalLines[$asset->id]->unit_price)->toBe(500.0);

    expect(DocumentRelationship::where('parent_document_id', $journal->id)
        ->where('child_document_id', $reversal->id)
        ->where('relationship_type', 'reversed_by')
        ->exists())->toBeTrue();

    // The original is untouched — reversal is a new document, not an edit.
    expect($journal->fresh()->status)->toBe('posted')
        ->and((float) $journal->fresh()->total)->toBe(500.0);
});

it('feeds the postings view with balanced debit/credit rows once posted', function (): void {
    $expense = Account::factory()->expense()->create();
    $asset = Account::factory()->asset()->create();

    $journal = journalDraft();
    journalLine($journal, $expense, 500.00, 1);
    journalLine($journal, $asset, -500.00, 2);
    $this->service->postJournal($journal->fresh(), $this->user);

    $rows = DB::table('postings')->where('document_id', $journal->id)->get();

    expect($rows)->toHaveCount(2)
        ->and((float) $rows->sum('debit'))->toBe(500.0)
        ->and((float) $rows->sum('credit'))->toBe(500.0);

    $expenseRow = $rows->firstWhere('account_id', $expense->id);
    $assetRow = $rows->firstWhere('account_id', $asset->id);

    expect((float) $expenseRow->debit)->toBe(500.0)
        ->and((float) $expenseRow->credit)->toBe(0.0)
        ->and((float) $assetRow->credit)->toBe(500.0)
        ->and((float) $assetRow->debit)->toBe(0.0);
});

it('does not appear in the postings view while still a draft', function (): void {
    $journal = journalDraft();
    journalLine($journal, Account::factory()->expense()->create(), 500.00, 1);
    journalLine($journal, Account::factory()->asset()->create(), -500.00, 2);

    expect(DB::table('postings')->where('document_id', $journal->id)->count())->toBe(0);
});
