<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Core\Models\BankReconciliationMatch;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentService;
use App\Modules\Core\Services\PartyService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function bssSentInvoice(): Document
{
    $client = app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Statement Test Client',
        'trading_name' => 'Statement Test Client',
        'status' => 'active',
    ], ['client']);

    $invoice = app(BillingService::class)->createDraft($client, ['issue_date' => now()->toDateString()]);
    $invoice->update(['receivable_account_id' => Account::factory()->create()->id]);

    $invoice->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Consulting',
        'quantity' => 1,
        'unit_price' => 1000.00,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_rate' => 0,
    ]);
    $invoice->recalculateTotals();
    app(DocumentService::class)->markAsSent($invoice, User::factory()->create());

    return $invoice->fresh();
}

function bssStatement(?Account $bankAccount = null): Document
{
    return Document::create([
        'document_type' => 'bank_statement',
        'direction' => 'inbound',
        'status' => 'received',
        'party_id' => Party::factory()->create()->id,
        'issue_date' => now()->toDateString(),
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'contra_account_id' => ($bankAccount ?? Account::factory()->create())->id,
        'reference' => 'STMT-001',
        'source' => 'manual',
    ]);
}

it('does not touch the ledger when a statement is imported', function (): void {
    bssSentInvoice(); // pre-existing invoice, so the journal isn't empty
    $before = (int) DB::table('postings')->count();

    $statement = bssStatement();
    $statement->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Payment received',
        'quantity' => 1,
        'unit_price' => 1000.00,
        'tax_rate' => null,
    ]);

    expect(DB::table('postings')->count())->toBe($before);
});

it('creates a payment and matches it when the operator confirms against an invoice', function (): void {
    $invoice = bssSentInvoice(); // total 1000, balance_due 1000
    $bankAccount = Account::factory()->create();
    $statement = bssStatement($bankAccount);

    $line = $statement->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Payment received',
        'quantity' => 1,
        'unit_price' => 1000.00,
        'tax_rate' => null,
    ]);

    $user = User::factory()->create();
    $user->givePermissionTo(['documents-view-any', 'documents-view', 'documents-update']);
    $this->actingAs($user);

    Livewire::test('pages.bank-statements.index')
        ->call('openDetail', $statement->id)
        ->set('createInvoiceId', $invoice->id)
        ->call('createPaymentForLine', $line->id);

    $fresh = $invoice->fresh();
    expect($fresh->status)->toBe('paid')
        ->and((float) $fresh->balance_due)->toBe(0.0);

    $payment = Document::where('document_type', 'payment')
        ->where('party_id', $invoice->party_id)
        ->latest()
        ->first();

    expect($payment)->not->toBeNull()
        ->and($payment->direction)->toBe('inbound')
        ->and((float) $payment->total)->toBe(1000.00)
        ->and($payment->receivable_account_id)->toBe($fresh->receivable_account_id)
        ->and($payment->contra_account_id)->toBe($bankAccount->id);

    $match = BankReconciliationMatch::where('statement_line_id', $line->id)->first();
    expect($match)->not->toBeNull()
        ->and($match->document_id)->toBe($payment->id);
});

it('matches a line against an existing payment without creating a new one', function (): void {
    $invoice = bssSentInvoice();
    $bankAccount = Account::factory()->create();

    $payment = app(BillingService::class)->recordPayment($invoice, [
        'amount' => 1000.00,
        'date' => now()->toDateString(),
        'contra_account_id' => $bankAccount->id,
    ], null);

    $statement = bssStatement($bankAccount);
    $line = $statement->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Payment received',
        'quantity' => 1,
        'unit_price' => 1000.00,
        'tax_rate' => null,
    ]);

    $documentsBefore = Document::where('document_type', 'payment')->count();
    $user = User::factory()->create();
    $user->givePermissionTo(['documents-view-any', 'documents-view', 'documents-update']);
    $this->actingAs($user);

    Livewire::test('pages.bank-statements.index')
        ->call('openDetail', $statement->id)
        ->call('matchLine', $line->id, $payment->id);

    expect(Document::where('document_type', 'payment')->count())->toBe($documentsBefore); // no new payment created

    $match = BankReconciliationMatch::where('statement_line_id', $line->id)->first();
    expect($match)->not->toBeNull()
        ->and($match->document_id)->toBe($payment->id);
});

it('posts a two-line GL entry for an unmatched line with no invoice behind it', function (): void {
    $bankAccount = Account::factory()->create();
    $bankCharges = Account::factory()->create();
    $statement = bssStatement($bankAccount);

    $line = $statement->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Monthly account fee',
        'quantity' => 1,
        'unit_price' => -150.00, // debit — money left the account
        'tax_rate' => null,
    ]);

    $user = User::factory()->create();
    $user->givePermissionTo(['documents-view-any', 'documents-view', 'documents-update']);
    $this->actingAs($user);

    Livewire::test('pages.bank-statements.index')
        ->call('openDetail', $statement->id)
        ->set('createGlAccountId', $bankCharges->id)
        ->call('postLineToGlAccount', $line->id);

    $entry = BankReconciliationMatch::where('statement_line_id', $line->id)->first()?->document;

    expect($entry)->not->toBeNull()
        ->and($entry->direction)->toBe('outbound')
        ->and((float) $entry->total)->toBe(150.00);

    expect((float) DB::table('postings')->where('account_id', $bankCharges->id)->sum('debit'))->toBe(150.0)
        ->and((float) DB::table('postings')->where('account_id', $bankAccount->id)->sum('credit'))->toBe(150.0);
});

it('unmatches a line, freeing it to be matched again', function (): void {
    $invoice = bssSentInvoice();
    $bankAccount = Account::factory()->create();
    $payment = app(BillingService::class)->recordPayment($invoice, [
        'amount' => 1000.00,
        'date' => now()->toDateString(),
        'contra_account_id' => $bankAccount->id,
    ], null);

    $statement = bssStatement($bankAccount);
    $line = $statement->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Payment received',
        'quantity' => 1,
        'unit_price' => 1000.00,
        'tax_rate' => null,
    ]);

    $user = User::factory()->create();
    $user->givePermissionTo(['documents-view-any', 'documents-view', 'documents-update']);
    $this->actingAs($user);
    app(DocumentService::class)->matchReconciliation($line, $payment, $user);

    Livewire::test('pages.bank-statements.index')
        ->call('openDetail', $statement->id)
        ->call('unmatchLine', $line->id);

    expect(BankReconciliationMatch::where('statement_line_id', $line->id)->exists())->toBeFalse();
});

it('closes a statement out as reconciled without posting anything', function (): void {
    $statement = bssStatement();
    $before = (int) DB::table('postings')->count();

    $user = User::factory()->create();
    $user->givePermissionTo(['documents-view-any', 'documents-view', 'documents-update']);
    $this->actingAs($user);

    Livewire::test('pages.bank-statements.index')
        ->call('openDetail', $statement->id)
        ->call('reconcileStatement', $statement->id);

    expect($statement->fresh()->status)->toBe('reconciled')
        ->and(DB::table('postings')->count())->toBe($before);
});
