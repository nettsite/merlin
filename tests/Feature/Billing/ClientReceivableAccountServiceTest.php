<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Services\ClientReceivableAccountService;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Services\PartyService;

function crasClientRel(string $name = 'Sub Account Test Client')
{
    $party = app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => $name,
        'trading_name' => $name,
        'status' => 'active',
    ], ['client']);

    return $party->relationships->firstWhere('relationship_type', 'client');
}

function crasSetControlAccount(): Account
{
    $control = Account::factory()->create();
    $settings = app(BillingSettings::class);
    $settings->default_receivable_account_id = $control->id;
    $settings->save();

    return $control;
}

it('creates a sub-account as a child of the configured control account', function (): void {
    $control = crasSetControlAccount();
    $rel = crasClientRel();

    $account = app(ClientReceivableAccountService::class)->getOrCreateForClient($rel);

    expect($account->parent_id)->toBe($control->id)
        ->and($account->account_group_id)->toBe($control->account_group_id)
        ->and($account->allow_direct_posting)->toBeFalse()
        ->and($account->code)->toStartWith("{$control->code}-");
});

it('is idempotent — a second call returns the same account', function (): void {
    crasSetControlAccount();
    $rel = crasClientRel();

    $first = app(ClientReceivableAccountService::class)->getOrCreateForClient($rel);
    $second = app(ClientReceivableAccountService::class)->getOrCreateForClient($rel->fresh());

    expect($second->id)->toBe($first->id);
});

it('increments the code sequence across multiple clients', function (): void {
    crasSetControlAccount();
    $service = app(ClientReceivableAccountService::class);

    $accountA = $service->getOrCreateForClient(crasClientRel('Client A'));
    $accountB = $service->getOrCreateForClient(crasClientRel('Client B'));

    expect($accountA->code)->not->toBe($accountB->code);
});

it('returns null when no control account is configured yet', function (): void {
    $rel = crasClientRel();

    $account = app(ClientReceivableAccountService::class)->getOrCreateForClient($rel);

    expect($account)->toBeNull();
});

it('treats a relationship still pointing at the shared control account as unmigrated', function (): void {
    $control = crasSetControlAccount();
    $rel = crasClientRel();
    $rel->mergeMetadata(['default_receivable_account_id' => $control->id]);

    $account = app(ClientReceivableAccountService::class)->getOrCreateForClient($rel->fresh());

    expect($account->id)->not->toBe($control->id)
        ->and($account->parent_id)->toBe($control->id);
});
