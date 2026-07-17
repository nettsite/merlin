<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\PartyRelationship;
use App\Modules\Purchasing\Settings\PurchasingSettings;
use Illuminate\Support\Facades\DB;

class SupplierPayableAccountService
{
    public function __construct(
        private readonly PurchasingSettings $purchasingSettings,
    ) {}

    /**
     * Get or create the supplier's own payable sub-account for a resolved
     * Party. Unlike the client-side equivalent, a party arriving here isn't
     * guaranteed to already have a 'supplier' relationship (SupplierResolver's
     * tax-number/name match can land on a party matched for other reasons),
     * so it's created here if missing.
     */
    public function getOrCreateForParty(Party $party): ?Account
    {
        $supplierRel = $party->relationships()->firstOrCreate(['relationship_type' => 'supplier']);

        return $this->getOrCreateForSupplier($supplierRel);
    }

    /**
     * Get the supplier's own payable account, creating it as a child of the
     * configured payable control account (PurchasingSettings->default_payable_account)
     * if one doesn't exist yet. Returns null if the control account isn't
     * configured/found yet (a valid, inert state) rather than failing
     * invoice processing.
     */
    public function getOrCreateForSupplier(PartyRelationship $supplierRel): ?Account
    {
        if ($supplierRel->default_payable_account_id !== null) {
            $existing = Account::find($supplierRel->default_payable_account_id);

            // A real per-supplier sub-account always has a parent (the control
            // account itself does not). A relationship still pointing straight
            // at the control account (manually set, or pre-dating this feature)
            // is not yet migrated.
            if ($existing !== null && $existing->parent_id !== null) {
                return $existing;
            }
        }

        $parent = Account::where('code', $this->purchasingSettings->default_payable_account)->first();

        if ($parent === null) {
            return null;
        }

        return DB::transaction(function () use ($supplierRel, $parent) {
            $name = $supplierRel->party->displayName;

            $sequence = Account::where('parent_id', $parent->id)->count() + 1;

            $account = Account::create([
                'account_group_id' => $parent->account_group_id,
                'parent_id' => $parent->id,
                'code' => sprintf('%s-%04d', $parent->code, $sequence),
                'name' => "{$parent->name} — {$name}",
                'allow_direct_posting' => false,
                'is_active' => true,
            ]);

            $supplierRel->mergeMetadata(['default_payable_account_id' => $account->id]);

            return $account;
        });
    }
}
