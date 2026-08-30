<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountGroup;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentService;
use App\Modules\Core\Services\PartyService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->actingAs(User::factory()->create());

    $this->income = Account::whereHas('group.type', fn ($q) => $q->where('code', '4'))
        ->where('allow_direct_posting', true)
        ->first();

    $assetGroup = AccountGroup::whereHas('type', fn ($q) => $q->where('code', '1'))->first();
    $this->receivable = Account::create([
        'account_group_id' => $assetGroup->id,
        'code' => '1199',
        'name' => 'Test Accounts Receivable',
        'allow_direct_posting' => true,
        'is_active' => true,
    ]);
});

/**
 * An issued sales invoice can no longer be voided — the reversal instrument
 * is a credit note, dated when the mistake is discovered rather than
 * retroactively erasing the invoice's own period. This mirrors the old
 * voidedSalesInvoice() helper (draft -> sent -> fully reversed) but via
 * createCreditNoteFromInvoice() -> issueCreditNote() -> applyCreditNote().
 */
function creditedSalesInvoice(Account $incomeAccount, float $unitPrice = 7777.77, ?Account $receivableAccount = null, ?string $partyId = null): Document
{
    $doc = Document::create([
        'document_type' => 'sales_invoice',
        'direction' => 'outbound',
        'status' => 'draft',
        'party_id' => $partyId,
        'issue_date' => now()->toDateString(),
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'source' => 'manual',
        'receivable_account_id' => $receivableAccount?->id,
    ]);

    $doc->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Cancelled work',
        'account_id' => $incomeAccount->id,
        'quantity' => 1,
        'unit_price' => $unitPrice,
        'tax_rate' => null,
    ]);

    $user = User::factory()->create();
    $svc = app(DocumentService::class);
    $svc->markAsSent($doc->fresh(), $user);

    $invoice = $doc->fresh();
    $creditNote = $svc->createCreditNoteFromInvoice($invoice, $user);
    $svc->issueCreditNote($creditNote->fresh(), $user);
    $svc->applyCreditNote($creditNote->fresh(), $invoice, $user);

    return $invoice->fresh();
}

it('excludes a fully credited sales invoice from the income statement', function (): void {
    creditedSalesInvoice($this->income, receivableAccount: $this->receivable);

    $data = Livewire::test('pages.reports.income-statement')->viewData('totalRevenueYtd');

    expect((float) $data)->toBe(0.0);
});

it('does not exclude a fully credited sales invoice from income by account (known gap, out of scope)', function (): void {
    // income-by-account reads documents directly and has never netted
    // credit notes against the invoices they reverse — that predates the
    // void removal and isn't something this phase changes. Documented here
    // so the gap is a known, tested fact rather than a silent surprise.
    creditedSalesInvoice($this->income, receivableAccount: $this->receivable);

    $rows = Livewire::test('pages.reports.income-by-account')->viewData('rows');

    expect($rows)->toHaveCount(1);
});

it('does not exclude a fully credited sales invoice from income by client (known gap, out of scope)', function (): void {
    $client = app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Report Filtering Client Ltd',
        'status' => 'active',
    ], ['client']);
    creditedSalesInvoice($this->income, receivableAccount: $this->receivable, partyId: $client->id);

    $rows = Livewire::test('pages.reports.income-by-client')->viewData('rows');

    expect($rows)->toHaveCount(1);
});

it('agrees on revenue between the income statement and the trial balance', function (): void {
    creditedSalesInvoice($this->income, receivableAccount: $this->receivable);

    $is = $this->get('/reports/income-statement')->getContent();
    expect($is)->not->toContain('7,777.77');

    // The trial balance's Movements columns legitimately show both the
    // invoice's original posting and the credit note's exact reversal (real
    // double-entry activity that happened in the period) — what must be
    // zero is the account's cumulative Balance, not the presence of the
    // figure anywhere in the page.
    $rows = Livewire::test('pages.reports.trial-balance')->viewData('rows');
    $incomeRow = $rows->flatten()->firstWhere('id', $this->income->id);

    expect($incomeRow)->not->toBeNull()
        ->and((float) $incomeRow->bal_debit)->toBe(0.0)
        ->and((float) $incomeRow->bal_credit)->toBe(0.0);
});

it('still recognises a sent (non-voided) sales invoice as revenue', function (): void {
    $doc = Document::create([
        'document_type' => 'sales_invoice',
        'direction' => 'outbound',
        'status' => 'draft',
        'issue_date' => now()->toDateString(),
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'source' => 'manual',
        'receivable_account_id' => $this->receivable->id,
    ]);

    $doc->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Live work',
        'account_id' => $this->income->id,
        'quantity' => 1,
        'unit_price' => 500.00,
        'tax_rate' => null,
    ]);

    app(DocumentService::class)->markAsSent($doc->fresh(), User::factory()->create());

    $data = Livewire::test('pages.reports.income-statement')->viewData('totalRevenueYtd');

    expect((float) $data)->toBe(500.0);
});
