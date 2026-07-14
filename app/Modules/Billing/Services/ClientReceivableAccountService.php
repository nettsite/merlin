<?php

namespace App\Modules\Billing\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\PartyRelationship;
use App\Modules\Core\Services\PartyService;
use Illuminate\Support\Facades\DB;

class ClientReceivableAccountService
{
    public function __construct(
        private readonly BillingSettings $billingSettings,
        private readonly PartyService $partyService,
    ) {}

    /**
     * Create a client business party and its own receivable sub-account in one step.
     *
     * @param  array<string, mixed>  $data
     */
    public function createClient(array $data): Party
    {
        $party = $this->partyService->createBusiness($data, ['client']);

        $clientRel = $party->relationships->firstWhere('relationship_type', 'client');
        $this->getOrCreateForClient($clientRel);

        return $party->load('relationships');
    }

    /**
     * Get the client's own receivable account, creating it as a child of the
     * configured receivable control account (BillingSettings->default_receivable_account_id)
     * if one doesn't exist yet. Returns null if no control account is configured
     * yet (a valid, inert state on a fresh install — Settings > Billing hasn't
     * been filled in) rather than failing client creation.
     */
    public function getOrCreateForClient(PartyRelationship $clientRel): ?Account
    {
        if ($clientRel->default_receivable_account_id !== null) {
            $existing = Account::find($clientRel->default_receivable_account_id);

            // A real per-client sub-account always has a parent (the control
            // account itself does not). Older data pointed every client at the
            // shared control account directly — that's not yet migrated.
            if ($existing !== null && $existing->parent_id !== null) {
                return $existing;
            }
        }

        if ($this->billingSettings->default_receivable_account_id === null) {
            return null;
        }

        return DB::transaction(function () use ($clientRel) {
            $parent = Account::findOrFail($this->billingSettings->default_receivable_account_id);
            $name = $clientRel->party->displayName;

            $sequence = Account::where('parent_id', $parent->id)->count() + 1;

            $account = Account::create([
                'account_group_id' => $parent->account_group_id,
                'parent_id' => $parent->id,
                'code' => sprintf('%s-%04d', $parent->code, $sequence),
                'name' => "{$parent->name} — {$name}",
                'allow_direct_posting' => false,
                'is_active' => true,
            ]);

            $clientRel->mergeMetadata(['default_receivable_account_id' => $account->id]);

            return $account;
        });
    }
}
