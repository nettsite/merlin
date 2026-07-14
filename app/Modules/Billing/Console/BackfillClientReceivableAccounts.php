<?php

namespace App\Modules\Billing\Console;

use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Services\ClientReceivableAccountService;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\PartyRelationship;
use Illuminate\Console\Command;

class BackfillClientReceivableAccounts extends Command
{
    protected $signature = 'accounts:backfill-client-receivables {--dry-run : Preview without writing}';

    protected $description = 'Create a receivable sub-account for every client missing one, and repoint their sales invoices to it.';

    public function handle(ClientReceivableAccountService $service, BillingSettings $billingSettings): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('[DRY RUN] No writes will occur.');
        }

        if ($billingSettings->default_receivable_account_id === null) {
            $this->error('No receivable control account configured (Settings > Billing). Set one before running this command.');

            return self::FAILURE;
        }

        // A real per-client sub-account always has a parent_id; the shared
        // control account itself (or a null metadata value) does not. Older
        // data pointed every client's metadata straight at the control
        // account, so a plain "is it null" check would miss all of them.
        $accountIsSubAccount = Account::whereIn(
            'id',
            PartyRelationship::query()->where('relationship_type', 'client')
                ->get()
                ->pluck('default_receivable_account_id')
                ->filter()
                ->unique(),
        )->pluck('parent_id', 'id');

        $relationships = PartyRelationship::query()
            ->where('relationship_type', 'client')
            ->with('party')
            ->get()
            ->filter(fn ($rel) => $rel->default_receivable_account_id === null
                || ($accountIsSubAccount[$rel->default_receivable_account_id] ?? null) === null);

        $this->info("Found {$relationships->count()} client relationship(s) missing a receivable sub-account.");

        $accountsCreated = 0;

        foreach ($relationships as $rel) {
            $name = $rel->party?->displayName ?? $rel->party_id;
            $this->line("  Client: {$name}");

            if ($isDryRun) {
                $accountsCreated++;

                continue;
            }

            $service->getOrCreateForClient($rel);
            $accountsCreated++;
        }

        $this->comment("Accounts created: {$accountsCreated}");

        $subAccountIds = Account::whereNotNull('parent_id')->pluck('id');

        $invoicesQuery = Document::query()
            ->where('document_type', 'sales_invoice')
            ->where(function ($q) use ($subAccountIds) {
                $q->whereNull('receivable_account_id')
                    ->orWhereNotIn('receivable_account_id', $subAccountIds);
            })
            ->whereHas('party.relationships', fn ($q) => $q->where('relationship_type', 'client'));

        $invoiceCount = (clone $invoicesQuery)->count();
        $this->info("Found {$invoiceCount} sales invoice(s) still pointing at the control account.");

        $invoicesUpdated = 0;

        if (! $isDryRun) {
            $invoicesQuery->with('party.relationships')->chunkById(100, function ($invoices) use ($service, &$invoicesUpdated) {
                foreach ($invoices as $invoice) {
                    $clientRel = $invoice->party->relationships->firstWhere('relationship_type', 'client');

                    if ($clientRel === null) {
                        continue;
                    }

                    $account = $service->getOrCreateForClient($clientRel);

                    if ($account === null) {
                        continue;
                    }

                    $invoice->update(['receivable_account_id' => $account->id]);
                    $invoicesUpdated++;
                }
            });
        }

        $this->comment('Invoices updated: '.($isDryRun ? $invoiceCount : $invoicesUpdated));

        return self::SUCCESS;
    }
}
