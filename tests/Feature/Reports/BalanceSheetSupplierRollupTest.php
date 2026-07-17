<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountGroup;
use App\Modules\Accounting\Models\AccountType;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\PartyService;
use App\Modules\Purchasing\Services\SupplierPayableAccountService;
use App\Modules\Purchasing\Settings\PurchasingSettings;
use Livewire\Livewire;

function bsrSupplier(string $name)
{
    $party = app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => $name,
        'trading_name' => $name,
        'status' => 'active',
    ], ['supplier']);

    $rel = $party->relationships->firstWhere('relationship_type', 'supplier');
    $account = app(SupplierPayableAccountService::class)->getOrCreateForSupplier($rel);

    return [$party, $account];
}

function bsrPostedPurchaseInvoice($party, Account $payableAccount, float $amount): Document
{
    $invoice = Document::factory()->purchaseInvoice()->create([
        'party_id' => $party->id,
        'payable_account_id' => $payableAccount->id,
        'status' => 'posted',
        'issue_date' => now()->toDateString(),
        'subtotal' => $amount,
        'tax_total' => 0,
        'total' => $amount,
        'balance_due' => $amount,
    ]);

    return $invoice;
}

it('rolls up supplier payable sub-accounts into a single control-account line on the balance sheet', function (): void {
    $liabilityType = AccountType::firstOrCreate(['code' => '2'], ['name' => 'Liability', 'normal_balance' => 'credit']);
    $liabilityGroup = AccountGroup::firstOrCreate(['code' => '20'], ['name' => 'Current Liabilities', 'account_type_id' => $liabilityType->id]);
    $control = Account::create([
        'account_group_id' => $liabilityGroup->id,
        'code' => '2000',
        'name' => 'Accounts Payable',
        'allow_direct_posting' => true,
        'is_active' => true,
    ]);

    $settings = app(PurchasingSettings::class);
    $settings->default_payable_account = '2000';
    $settings->save();

    [$supplierA, $accountA] = bsrSupplier('Rollup Supplier A');
    [$supplierB, $accountB] = bsrSupplier('Rollup Supplier B');

    bsrPostedPurchaseInvoice($supplierA, $accountA, 1000.00);
    bsrPostedPurchaseInvoice($supplierB, $accountB, 500.00);

    $this->actingAs(User::factory()->create());

    $response = Livewire::test('pages.reports.balance-sheet')->assertOk();

    $liabilities = $response->viewData('liabilities');
    $accountCodes = $liabilities->flatten()->pluck('code')->all();
    $subAccountCodes = array_filter($accountCodes, fn ($code) => str_starts_with($code, '2000-'));

    expect($accountCodes)->toContain('2000')
        ->and($subAccountCodes)->toBe([]);

    $controlRow = $liabilities->flatten()->firstWhere('code', '2000');
    expect((float) $controlRow->balance)->toBe(1500.0);
});
