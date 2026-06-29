<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());

    $this->account = Account::factory()->create([
        'code' => '5210',
        'name' => 'IT & Software',
        'is_active' => true,
        'allow_direct_posting' => true,
    ]);
});

function expenseInvoice(Account $account, float $unitPrice, string $status = 'posted'): Document
{
    $doc = Document::factory()->purchaseInvoice()->create([
        'status' => $status,
        'issue_date' => now()->toDateString(),
    ]);

    $doc->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Service',
        'account_id' => $account->id,
        'quantity' => 1,
        'unit_price' => $unitPrice,
        'tax_rate' => 15,
    ]);

    return $doc;
}

it('aggregates posted invoice lines by account', function (): void {
    expenseInvoice($this->account, 100.00);
    expenseInvoice($this->account, 200.00);

    $rows = Livewire::test('pages.reports.expenses-by-account')->viewData('rows');

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->account_code)->toBe('5210')
        ->and((int) $rows->first()->invoice_count)->toBe(2)
        ->and((float) $rows->first()->total_excl)->toBe(300.00)
        ->and((float) $rows->first()->total_vat)->toBe(45.00);
});

it('excludes non-posted invoices from the account report', function (): void {
    expenseInvoice($this->account, 100.00);
    expenseInvoice($this->account, 999.00, status: 'received');

    $rows = Livewire::test('pages.reports.expenses-by-account')->viewData('rows');

    expect((float) $rows->first()->total_excl)->toBe(100.00);
});

it('excludes soft-deleted documents from the account report', function (): void {
    expenseInvoice($this->account, 100.00);
    expenseInvoice($this->account, 500.00)->delete();

    $rows = Livewire::test('pages.reports.expenses-by-account')->viewData('rows');

    expect((float) $rows->first()->total_excl)->toBe(100.00);
});
