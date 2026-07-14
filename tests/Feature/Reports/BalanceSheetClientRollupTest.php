<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountGroup;
use App\Modules\Accounting\Models\AccountType;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Billing\Services\ClientReceivableAccountService;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentService;
use App\Modules\Core\Services\PartyService;
use Livewire\Livewire;

function bsrClient(string $name)
{
    $party = app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => $name,
        'trading_name' => $name,
        'status' => 'active',
    ], ['client']);

    $rel = $party->relationships->firstWhere('relationship_type', 'client');
    app(ClientReceivableAccountService::class)->getOrCreateForClient($rel);

    return $party;
}

function bsrSentInvoice($client, float $amount): Document
{
    $invoice = app(BillingService::class)->createDraft($client, ['issue_date' => now()->toDateString()]);

    $invoice->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Consulting',
        'quantity' => 1,
        'unit_price' => $amount,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_rate' => 0,
    ]);
    $invoice->recalculateTotals();
    app(DocumentService::class)->markAsSent($invoice, User::factory()->create());

    return $invoice->fresh();
}

it('rolls up client receivable sub-accounts into a single control-account line on the balance sheet', function (): void {
    $assetType = AccountType::firstOrCreate(['code' => '1'], ['name' => 'Asset', 'normal_balance' => 'debit']);
    $assetGroup = AccountGroup::firstOrCreate(['code' => '10'], ['name' => 'Current Assets', 'account_type_id' => $assetType->id]);
    $control = Account::create([
        'account_group_id' => $assetGroup->id,
        'code' => '1100',
        'name' => 'Accounts Receivable',
        'allow_direct_posting' => true,
        'is_active' => true,
    ]);

    $settings = app(BillingSettings::class);
    $settings->default_receivable_account_id = $control->id;
    $settings->save();

    bsrSentInvoice(bsrClient('Rollup Client A'), 1000.00);
    bsrSentInvoice(bsrClient('Rollup Client B'), 500.00);

    $this->actingAs(User::factory()->create());

    $response = Livewire::test('pages.reports.balance-sheet')->assertOk();

    $assets = $response->viewData('assets');
    $accountCodes = $assets->flatten()->pluck('code')->all();
    $subAccountCodes = array_filter($accountCodes, fn ($code) => str_starts_with($code, '1100-'));

    expect($accountCodes)->toContain('1100')
        ->and($subAccountCodes)->toBe([]);

    $controlRow = $assets->flatten()->firstWhere('code', '1100');
    expect((float) $controlRow->balance)->toBe(1500.0);
});
