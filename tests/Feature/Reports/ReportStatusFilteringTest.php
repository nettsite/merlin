<?php

use App\Modules\Accounting\Models\Account;
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
});

function voidedSalesInvoice(Account $incomeAccount, float $unitPrice = 7777.77): Document
{
    $doc = Document::create([
        'document_type' => 'sales_invoice',
        'direction' => 'outbound',
        'status' => 'draft',
        'issue_date' => now()->toDateString(),
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'source' => 'manual',
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
    voidedSalesInvoice($this->income);

    $data = Livewire::test('pages.reports.income-statement')->viewData('totalRevenueYtd');

    expect((float) $data)->toBe(0.0);
});

it('excludes a voided sales invoice from income by account', function (): void {
    voidedSalesInvoice($this->income);

    $rows = Livewire::test('pages.reports.income-by-account')->viewData('rows');

    expect($rows)->toHaveCount(0);
});

it('excludes a voided sales invoice from income by client', function (): void {
    voidedSalesInvoice($this->income);

    $rows = Livewire::test('pages.reports.income-by-client')->viewData('rows');

    expect($rows)->toHaveCount(0);
});

it('agrees on revenue between the income statement and the trial balance', function (): void {
    voidedSalesInvoice($this->income);

    $is = $this->get('/reports/income-statement')->getContent();
    $tb = $this->get('/reports/trial-balance')->getContent();

    expect($is)->not->toContain('7,777.77')
        ->and($tb)->not->toContain('7,777.77');
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
