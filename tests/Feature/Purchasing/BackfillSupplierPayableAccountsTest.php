<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\PartyRelationship;
use App\Modules\Core\Services\PartyService;
use App\Modules\Purchasing\Settings\PurchasingSettings;

function bfSupplier(string $name, string $payableAccountId)
{
    $party = app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => $name,
        'trading_name' => $name,
        'status' => 'active',
    ], ['supplier']);

    $rel = $party->relationships->firstWhere('relationship_type', 'supplier');
    // Simulate the old behaviour: every supplier points straight at the shared control account.
    $rel->mergeMetadata(['default_payable_account_id' => $payableAccountId]);

    return $party;
}

it('fails without a configured control account', function (): void {
    $this->artisan('accounts:backfill-supplier-payables')->assertFailed();
});

it('migrates suppliers still pointing at the control account and repoints their invoices', function (): void {
    $control = Account::factory()->create(['code' => '2000', 'allow_direct_posting' => true]);
    app(PurchasingSettings::class)->default_payable_account = '2000';
    app(PurchasingSettings::class)->save();

    $supplier = bfSupplier('Backfill Supplier', $control->id);

    $invoice = Document::factory()->purchaseInvoice()->create([
        'party_id' => $supplier->id,
        'payable_account_id' => $control->id,
    ]);

    $this->artisan('accounts:backfill-supplier-payables')->assertSuccessful();

    $rel = PartyRelationship::where('party_id', $supplier->id)->where('relationship_type', 'supplier')->first();
    $accountId = $rel->metadata['default_payable_account_id'];

    expect($accountId)->not->toBe($control->id);
    expect(Account::find($accountId)->parent_id)->toBe($control->id);
    expect($invoice->fresh()->payable_account_id)->toBe($accountId);
});

it('is idempotent — a second run makes no further changes', function (): void {
    $control = Account::factory()->create(['code' => '2000', 'allow_direct_posting' => true]);
    app(PurchasingSettings::class)->default_payable_account = '2000';
    app(PurchasingSettings::class)->save();

    bfSupplier('Idempotent Supplier', $control->id);

    $this->artisan('accounts:backfill-supplier-payables')->assertSuccessful();
    $accountCountAfterFirst = Account::count();

    $this->artisan('accounts:backfill-supplier-payables')->assertSuccessful();
    $accountCountAfterSecond = Account::count();

    expect($accountCountAfterSecond)->toBe($accountCountAfterFirst);
});
