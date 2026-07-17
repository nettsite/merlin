<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Contracts\IncidentDetector;
use App\Modules\Purchasing\Settings\PurchasingSettings;

/**
 * Fires when the configured Accounts Payable control account
 * (PurchasingSettings->default_payable_account) doesn't resolve to a real,
 * active account. Every purchase invoice's payable posting depends on this
 * resolving correctly (see SupplierPayableAccountService) — if it's broken,
 * invoices would otherwise post nowhere or fall back to the unqualified
 * control account, silently corrupting the books. WatchInvoiceFolderCommand
 * checks this same condition before processing any files at all.
 */
class InvalidPurchasingSettingsIncidentDetector implements IncidentDetector
{
    public function __construct(
        private readonly PurchasingSettings $purchasingSettings,
    ) {}

    public function type(): string
    {
        return 'invalid_purchasing_settings';
    }

    public function check(): ?array
    {
        $code = $this->purchasingSettings->default_payable_account;

        $account = Account::where('code', $code)->where('is_active', true)->first();

        if ($account !== null) {
            return null;
        }

        return [
            'title' => 'Purchasing not configured',
            'message' => "The configured Accounts Payable control account ('{$code}') doesn't exist or is inactive. Purchase invoice processing is paused until this is fixed in Settings > Purchasing.",
            'metadata' => ['configured_code' => $code],
        ];
    }
}
