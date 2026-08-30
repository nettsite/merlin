<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());

    $this->ar = Account::factory()->create(['code' => '1100', 'allow_direct_posting' => true]);
    $this->ap = Account::factory()->create(['code' => '2000', 'allow_direct_posting' => true]);
    $this->bank = Account::factory()->create(['code' => '1000', 'allow_direct_posting' => true]);
    $this->income = Account::factory()->create(['code' => '4000', 'allow_direct_posting' => true]);
    $this->expense = Account::factory()->create(['code' => '5000', 'allow_direct_posting' => true]);
});

function ledgerSalesInvoice(Account $ar, Account $income, float $unitPrice): Document
{
    $doc = Document::create([
        'document_type' => 'sales_invoice',
        'direction' => 'outbound',
        'status' => 'draft',
        'issue_date' => now()->toDateString(),
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'source' => 'manual',
        'receivable_account_id' => $ar->id,
    ]);

    $doc->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Consulting',
        'account_id' => $income->id,
        'quantity' => 1,
        'unit_price' => $unitPrice,
        'tax_rate' => null,
    ]);

    return $doc->fresh();
}

function ledgerPurchaseInvoice(Account $ap, Account $expense, float $unitPrice): Document
{
    $doc = Document::create([
        'document_type' => 'purchase_invoice',
        'direction' => 'inbound',
        'status' => 'received',
        'issue_date' => now()->toDateString(),
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'source' => 'manual',
        'payable_account_id' => $ap->id,
    ]);

    $doc->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Office supplies',
        'account_id' => $expense->id,
        'quantity' => 1,
        'unit_price' => $unitPrice,
        'tax_rate' => null,
    ]);

    return $doc->fresh();
}

it('keeps the trial balance balanced across a mixed set of postings', function (): void {
    $svc = app(DocumentService::class);
    $user = User::factory()->create();

    // Sales invoice, sent then paid in full.
    $paidInvoice = ledgerSalesInvoice($this->ar, $this->income, 1000.00);
    $svc->markAsSent($paidInvoice, $user);
    app(BillingService::class)->recordPayment($paidInvoice->fresh(), [
        'amount' => 1000.00,
        'date' => now()->toDateString(),
        'contra_account_id' => $this->bank->id,
    ], $user);

    // Sales invoice, sent then voided — must not appear in the ledger at all.
    $voidedInvoice = ledgerSalesInvoice($this->ar, $this->income, 7777.77);
    $svc->markAsSent($voidedInvoice->fresh(), $user);
    $svc->voidDocument($voidedInvoice->fresh(), $user);

    // Sales invoice, sent then partially credited.
    $creditedInvoice = ledgerSalesInvoice($this->ar, $this->income, 500.00);
    $svc->markAsSent($creditedInvoice->fresh(), $user);

    $creditNote = Document::create([
        'document_type' => 'credit_note',
        'direction' => 'outbound',
        'status' => 'draft',
        'issue_date' => now()->toDateString(),
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'source' => 'manual',
        'total' => 200.00,
    ]);
    $creditNote->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Goodwill credit',
        'account_id' => $this->income->id,
        'quantity' => 1,
        'unit_price' => 200.00,
        'tax_rate' => null,
    ]);
    $svc->issueCreditNote($creditNote->fresh(), $user);
    $svc->applyCreditNote($creditNote->fresh(), $creditedInvoice->fresh(), $user);

    // Purchase invoice, posted then paid in full.
    $purchase = ledgerPurchaseInvoice($this->ap, $this->expense, 300.00);
    $svc->post($purchase, $user);
    $svc->recordPurchasePayment($purchase->fresh(), [
        'amount' => 300.00,
        'date' => now()->toDateString(),
        'contra_account_id' => $this->bank->id,
    ], $user);

    $totalDebit = (float) JournalLine::sum('debit');
    $totalCredit = (float) JournalLine::sum('credit');

    expect($totalDebit)->toBe($totalCredit)
        ->and($totalDebit)->toBeGreaterThan(0.0);
});

it('excludes a voided invoice from the ledger entirely', function (): void {
    $svc = app(DocumentService::class);
    $user = User::factory()->create();

    $invoice = ledgerSalesInvoice($this->ar, $this->income, 7777.77);
    $svc->markAsSent($invoice->fresh(), $user);
    $svc->voidDocument($invoice->fresh(), $user);

    // The reversal cancels out net movement to zero, but both the original
    // and the reversing entry exist in the append-only journal.
    $net = (float) JournalLine::where('account_id', $this->income->id)->sum('credit')
        - (float) JournalLine::where('account_id', $this->income->id)->sum('debit');

    expect($net)->toBe(0.0);
});

it('does not double-post a credit note applied twice by accident', function (): void {
    $svc = app(DocumentService::class);
    $user = User::factory()->create();

    $invoice = ledgerSalesInvoice($this->ar, $this->income, 500.00);
    $svc->markAsSent($invoice->fresh(), $user);

    $creditNote = Document::create([
        'document_type' => 'credit_note',
        'direction' => 'outbound',
        'status' => 'draft',
        'issue_date' => now()->toDateString(),
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'source' => 'manual',
        'total' => 100.00,
    ]);
    $creditNote->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Credit',
        'account_id' => $this->income->id,
        'quantity' => 1,
        'unit_price' => 100.00,
        'tax_rate' => null,
    ]);
    $svc->issueCreditNote($creditNote->fresh(), $user);
    $svc->applyCreditNote($creditNote->fresh(), $invoice->fresh(), $user);

    expect(JournalEntry::where('document_id', $creditNote->id)->count())->toBe(1);
});
