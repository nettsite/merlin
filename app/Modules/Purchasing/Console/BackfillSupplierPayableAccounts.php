<?php

namespace App\Modules\Purchasing\Console;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\PartyRelationship;
use App\Modules\Purchasing\Services\SupplierPayableAccountService;
use App\Modules\Purchasing\Settings\PurchasingSettings;
use Illuminate\Console\Command;

class BackfillSupplierPayableAccounts extends Command
{
    protected $signature = 'accounts:backfill-supplier-payables {--dry-run : Preview without writing}';

    protected $description = 'Create a payable sub-account for every supplier missing one, and repoint their purchase invoices to it.';

    public function handle(SupplierPayableAccountService $service, PurchasingSettings $purchasingSettings): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('[DRY RUN] No writes will occur.');
        }

        $controlAccount = Account::where('code', $purchasingSettings->default_payable_account)->first();

        if ($controlAccount === null) {
            $this->error("No payable control account found for code '{$purchasingSettings->default_payable_account}' (Settings > Purchasing). Set one before running this command.");

            return self::FAILURE;
        }

        // A real per-supplier sub-account always has a parent_id; the shared
        // control account itself (or a null metadata value) does not. Data
        // that pre-dates this feature points every supplier's metadata
        // straight at the control account, so a plain "is it null" check
        // would miss all of them.
        $accountIsSubAccount = Account::whereIn(
            'id',
            PartyRelationship::query()->where('relationship_type', 'supplier')
                ->get()
                ->pluck('default_payable_account_id')
                ->filter()
                ->unique(),
        )->pluck('parent_id', 'id');

        $relationships = PartyRelationship::query()
            ->where('relationship_type', 'supplier')
            ->with('party')
            ->get()
            ->filter(fn ($rel) => $rel->default_payable_account_id === null
                || ($accountIsSubAccount[$rel->default_payable_account_id] ?? null) === null);

        $this->info("Found {$relationships->count()} supplier relationship(s) missing a payable sub-account.");

        $accountsCreated = 0;

        foreach ($relationships as $rel) {
            $name = $rel->party?->displayName ?? $rel->party_id;
            $this->line("  Supplier: {$name}");

            if ($isDryRun) {
                $accountsCreated++;

                continue;
            }

            $service->getOrCreateForSupplier($rel);
            $accountsCreated++;
        }

        $this->comment("Accounts created: {$accountsCreated}");

        $subAccountIds = Account::whereNotNull('parent_id')->pluck('id');

        $invoicesQuery = Document::query()
            ->where('document_type', 'purchase_invoice')
            ->where(function ($q) use ($subAccountIds) {
                $q->whereNull('payable_account_id')
                    ->orWhereNotIn('payable_account_id', $subAccountIds);
            })
            ->whereHas('party.relationships', fn ($q) => $q->where('relationship_type', 'supplier'));

        $invoiceCount = (clone $invoicesQuery)->count();
        $this->info("Found {$invoiceCount} purchase invoice(s) still pointing at the control account.");

        $invoicesUpdated = 0;

        if (! $isDryRun) {
            $invoicesQuery->with('party.relationships')->chunkById(100, function ($invoices) use ($service, &$invoicesUpdated) {
                foreach ($invoices as $invoice) {
                    $supplierRel = $invoice->party->relationships->firstWhere('relationship_type', 'supplier');

                    if ($supplierRel === null) {
                        continue;
                    }

                    $account = $service->getOrCreateForSupplier($supplierRel);

                    if ($account === null) {
                        continue;
                    }

                    $invoice->update(['payable_account_id' => $account->id]);
                    $invoicesUpdated++;
                }
            });
        }

        $this->comment('Invoices updated: '.($isDryRun ? $invoiceCount : $invoicesUpdated));

        return self::SUCCESS;
    }
}
