<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountGroup;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentService;
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

function voidedSalesInvoice(Account $incomeAccount, float $unitPrice = 7777.77, ?Account $receivableAccount = null): Document
{
    $doc = Document::create([
        'document_type' => 'sales_invoice',
        'direction' => 'outbound',
        'status' => 'draft',
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
    $svc->voidDocument($doc->fresh(), $user);

    return $doc->fresh();
}

it('excludes a voided sales invoice from the income statement', function (): void {
    voidedSalesInvoice($this->income, receivableAccount: $this->receivable);

    $data = Livewire::test('pages.reports.income-statement')->viewData('totalRevenueYtd');

    expect((float) $data)->toBe(0.0);
});

it('excludes a voided sales invoice from income by account', function (): void {
    voidedSalesInvoice($this->income, receivableAccount: $this->receivable);

    $rows = Livewire::test('pages.reports.income-by-account')->viewData('rows');

    expect($rows)->toHaveCount(0);
});

it('excludes a voided sales invoice from income by client', function (): void {
    voidedSalesInvoice($this->income, receivableAccount: $this->receivable);

    $rows = Livewire::test('pages.reports.income-by-client')->viewData('rows');

    expect($rows)->toHaveCount(0);
});

it('agrees on revenue between the income statement and the trial balance', function (): void {
    voidedSalesInvoice($this->income, receivableAccount: $this->receivable);

    $is = $this->get('/reports/income-statement')->getContent();
    expect($is)->not->toContain('7,777.77');

    // The trial balance's Movements columns legitimately show a voided
    // invoice's original posting AND its exact reversal (real double-entry
    // activity that happened in the period) — what must be zero is the
    // account's cumulative Balance, not the presence of the figure anywhere
    // in the page.
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
