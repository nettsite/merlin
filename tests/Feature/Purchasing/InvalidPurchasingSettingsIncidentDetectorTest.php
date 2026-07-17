<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Purchasing\Services\InvalidPurchasingSettingsIncidentDetector;
use App\Modules\Purchasing\Settings\PurchasingSettings;

it('is clear when the configured control account exists and is active', function (): void {
    Account::factory()->create(['code' => '2000', 'is_active' => true]);

    expect(app(InvalidPurchasingSettingsIncidentDetector::class)->check())->toBeNull();
});

it('fires when the configured control account does not exist', function (): void {
    $result = app(InvalidPurchasingSettingsIncidentDetector::class)->check();

    expect($result)->not->toBeNull()
        ->and($result['metadata']['configured_code'])->toBe('2000');
});

it('fires when the configured control account exists but is inactive', function (): void {
    Account::factory()->create(['code' => '2000', 'is_active' => false]);

    expect(app(InvalidPurchasingSettingsIncidentDetector::class)->check())->not->toBeNull();
});

it('tracks whatever code is currently configured, not just 2000', function (): void {
    $settings = app(PurchasingSettings::class);
    $settings->default_payable_account = '9999';
    $settings->save();

    Account::factory()->create(['code' => '2000', 'is_active' => true]);

    $result = app(InvalidPurchasingSettingsIncidentDetector::class)->check();

    expect($result)->not->toBeNull()
        ->and($result['metadata']['configured_code'])->toBe('9999');
});
