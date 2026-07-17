<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Services\PartyService;
use App\Modules\Purchasing\Services\SupplierPayableAccountService;
use App\Modules\Purchasing\Settings\PurchasingSettings;

function spasSupplierRel(string $name = 'Sub Account Test Supplier')
{
    $party = app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => $name,
        'trading_name' => $name,
        'status' => 'active',
    ], ['supplier']);

    return $party->relationships->firstWhere('relationship_type', 'supplier');
}

function spasSetControlAccount(): Account
{
    $control = Account::factory()->create(['code' => '2000']);
    app(PurchasingSettings::class)->default_payable_account = $control->code;
    app(PurchasingSettings::class)->save();

    return $control;
}

it('creates a sub-account as a child of the configured control account', function (): void {
    $control = spasSetControlAccount();
    $rel = spasSupplierRel();

    $account = app(SupplierPayableAccountService::class)->getOrCreateForSupplier($rel);

    expect($account->parent_id)->toBe($control->id)
        ->and($account->account_group_id)->toBe($control->account_group_id)
        ->and($account->allow_direct_posting)->toBeFalse()
        ->and($account->code)->toStartWith("{$control->code}-");
});

it('is idempotent — a second call returns the same account', function (): void {
    spasSetControlAccount();
    $rel = spasSupplierRel();

    $first = app(SupplierPayableAccountService::class)->getOrCreateForSupplier($rel);
    $second = app(SupplierPayableAccountService::class)->getOrCreateForSupplier($rel->fresh());

    expect($second->id)->toBe($first->id);
});

it('increments the code sequence across multiple suppliers', function (): void {
    spasSetControlAccount();
    $service = app(SupplierPayableAccountService::class);

    $accountA = $service->getOrCreateForSupplier(spasSupplierRel('Supplier A'));
    $accountB = $service->getOrCreateForSupplier(spasSupplierRel('Supplier B'));

    expect($accountA->code)->not->toBe($accountB->code);
});

it('returns null when no control account is configured yet', function (): void {
    $rel = spasSupplierRel();

    $account = app(SupplierPayableAccountService::class)->getOrCreateForSupplier($rel);

    expect($account)->toBeNull();
});

it('treats a relationship still pointing at the shared control account as unmigrated', function (): void {
    $control = spasSetControlAccount();
    $rel = spasSupplierRel();
    $rel->mergeMetadata(['default_payable_account_id' => $control->id]);

    $account = app(SupplierPayableAccountService::class)->getOrCreateForSupplier($rel->fresh());

    expect($account->id)->not->toBe($control->id)
        ->and($account->parent_id)->toBe($control->id);
});

it('creates the supplier relationship when resolving from a Party that does not have one yet', function (): void {
    $control = spasSetControlAccount();

    $party = app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'No Relationship Yet',
        'trading_name' => 'No Relationship Yet',
        'status' => 'active',
    ], []); // no relationships created

    $account = app(SupplierPayableAccountService::class)->getOrCreateForParty($party);

    expect($account)->not->toBeNull()
        ->and($account->parent_id)->toBe($control->id)
        ->and($party->relationships()->where('relationship_type', 'supplier')->exists())->toBeTrue();
});

it('gaining a sub-account blocks direct posting on the control account itself', function (): void {
    $control = spasSetControlAccount();
    $rel = spasSupplierRel();

    app(SupplierPayableAccountService::class)->getOrCreateForSupplier($rel);

    expect($control->fresh()->allow_direct_posting)->toBeFalse();
});
