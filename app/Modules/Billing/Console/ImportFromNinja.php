<?php

namespace App\Modules\Billing\Console;

use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Enums\RecurringFrequency;
use App\Modules\Billing\Enums\RecurringInvoiceStatus;
use App\Modules\Billing\Models\RecurringInvoice;
use App\Modules\Billing\Models\RecurringInvoiceLine;
use App\Modules\Core\Models\Address;
use App\Modules\Core\Models\ContactAssignment;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentLine;
use App\Modules\Core\Models\DocumentRelationship;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\PartyRelationship;
use App\Modules\Core\Services\PartyService;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportFromNinja extends Command
{
    protected $signature = 'ninja:import
        {path? : InvoiceNinja install directory (e.g. /home/will/NettSite/Billing/ninja)}
        {--dry-run : Show counts only, no writes}
        {--only= : Run only one phase: clients|contacts|invoices|quotes|credits|payments|recurring}
        {--limit=0 : Limit rows per phase for testing (0 = no limit)}';

    protected $description = 'Import billing data from an InvoiceNinja v5 installation into Merlin.';

    /** @var array<int, string> ninja client id → merlin party uuid */
    private array $clientMap = [];

    /** @var array<int, string> ninja invoice id → merlin document uuid */
    private array $invoiceMap = [];

    /** @var array<int, string> ninja credit id → merlin document uuid */
    private array $creditMap = [];

    private string $revenueAccountId = '';

    private string $receivableAccountId = '';

    private string $bankAccountId = '';

    private int $created = 0;

    private int $skipped = 0;

    private int $errored = 0;

    private const ALL_PHASES = ['clients', 'contacts', 'invoices', 'quotes', 'credits', 'recurring'];

    public function handle(PartyService $partyService): int
    {
        $path = $this->argument('path') ?? $this->ask('InvoiceNinja install path?');

        if (! $path || ! file_exists("{$path}/.env")) {
            $this->error("No .env found at {$path}/.env");

            return self::FAILURE;
        }

        $this->configureNinjaConnection($path);

        if (! $this->loadAccountIds()) {
            return self::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $only = $this->option('only');
        $limit = (int) $this->option('limit');

        if ($isDryRun) {
            $this->warn('[DRY RUN] No writes will occur.');
        }

        if ($only && ! in_array($only, self::ALL_PHASES)) {
            $this->error("Unknown phase: {$only}. Valid: ".implode(', ', self::ALL_PHASES));

            return self::FAILURE;
        }

        // Pre-populate maps when running a single phase that depends on prior phases.
        if ($only && $only !== 'clients') {
            $this->loadClientMap();
        }

        $summary = [];

        foreach (self::ALL_PHASES as $phase) {
            if ($only && $only !== $phase) {
                continue;
            }

            $this->resetCounters();
            $this->info("\n=== Phase: {$phase} ===");

            match ($phase) {
                'clients' => $this->importClients($partyService, $isDryRun, $limit),
                'contacts' => $this->importContacts($partyService, $isDryRun, $limit),
                'invoices' => $this->importInvoices($isDryRun, $limit),
                'quotes' => $this->importQuotes($isDryRun, $limit),
                'credits' => $this->importCredits($isDryRun, $limit),
                'recurring' => $this->importRecurring($isDryRun, $limit),
            };

            $summary[$phase] = [
                'created' => $this->created,
                'skipped' => $this->skipped,
                'errors' => $this->errored,
            ];

            $this->comment("  Done — created: {$this->created}, skipped: {$this->skipped}, errors: {$this->errored}");
        }

        $this->printSummaryTable($summary);

        return array_sum(array_column($summary, 'errors')) > 0 ? self::FAILURE : self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Phase: Clients
    // -------------------------------------------------------------------------

    private function importClients(PartyService $partyService, bool $dry, int $limit): void
    {
        $query = $this->ninja('clients')
            ->where('is_deleted', 0)
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->cursor() as $client) {
            $this->line("  Client #{$client->id}: {$client->name}");

            $existing = PartyRelationship::query()
                ->where('relationship_type', 'client')
                ->whereJsonContains('metadata->ninja_id', $client->id)
                ->first();

            if ($existing) {
                $this->clientMap[$client->id] = $existing->party_id;
                $this->skipped++;

                continue;
            }

            if ($dry) {
                $this->created++;

                continue;
            }

            try {
                DB::transaction(function () use ($client, $partyService) {
                    $settings = $this->parseJson($client->settings) ?? [];

                    $party = $partyService->createBusiness([
                        'status' => 'active',
                        'primary_phone' => $client->phone ?: null,
                        'business_type' => 'company',
                        'legal_name' => $client->name,
                        'trading_name' => $client->name,
                        'registration_number' => $client->id_number ?: null,
                        'tax_number' => $client->vat_number ?: null,
                        'website' => $client->website ?: null,
                        'default_currency' => $this->ninjaCurrencyCode($settings['currency_id'] ?? null),
                    ], ['client']);

                    $rel = $party->relationships->firstWhere('relationship_type', 'client');
                    $rel->mergeMetadata([
                        'ninja_id' => $client->id,
                        'default_receivable_account_id' => $this->receivableAccountId,
                    ]);

                    if ($client->address1 && $client->city) {
                        Address::create([
                            'party_id' => $party->id,
                            'type' => 'billing',
                            'line_1' => $client->address1,
                            'line_2' => $client->address2 ?: null,
                            'city' => $client->city,
                            'state_province' => $client->state ?: null,
                            'postal_code' => $client->postal_code ?: null,
                            'country' => $this->ninjaCountryCode((int) $client->country_id),
                            'is_primary' => true,
                            'is_active' => true,
                        ]);
                    }

                    if ($client->shipping_address1 && $client->shipping_city) {
                        Address::create([
                            'party_id' => $party->id,
                            'type' => 'shipping',
                            'line_1' => $client->shipping_address1,
                            'line_2' => $client->shipping_address2 ?: null,
                            'city' => $client->shipping_city,
                            'state_province' => $client->shipping_state ?: null,
                            'postal_code' => $client->shipping_postal_code ?: null,
                            'country' => $this->ninjaCountryCode((int) $client->shipping_country_id),
                            'is_primary' => false,
                            'is_active' => true,
                        ]);
                    }

                    $this->clientMap[$client->id] = $party->id;
                });

                $this->created++;
            } catch (Throwable $e) {
                $this->error("    Error: {$e->getMessage()}");
                Log::error("ninja:import clients — #{$client->id}: {$e->getMessage()}");
                $this->errored++;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Phase: Contacts
    // -------------------------------------------------------------------------

    private function importContacts(PartyService $partyService, bool $dry, int $limit): void
    {
        $query = $this->ninja('client_contacts')
            ->whereNull('deleted_at')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->cursor() as $contact) {
            $partyUuid = $this->clientMap[$contact->client_id] ?? null;

            if (! $partyUuid) {
                $this->warn("  Contact #{$contact->id}: client #{$contact->client_id} not mapped — skip");
                $this->skipped++;

                continue;
            }

            $this->line("  Contact #{$contact->id}: {$contact->first_name} {$contact->last_name}");

            if ($contact->email) {
                $exists = ContactAssignment::query()
                    ->where('party_id', $partyUuid)
                    ->whereHas('person', fn ($q) => $q->where('email', $contact->email))
                    ->exists();

                if ($exists) {
                    $this->skipped++;

                    continue;
                }
            }

            if ($dry) {
                $this->created++;

                continue;
            }

            try {
                DB::transaction(function () use ($contact, $partyUuid, $partyService) {
                    $personParty = $partyService->createPerson([
                        'status' => 'active',
                        'first_name' => $contact->first_name ?: '(unknown)',
                        'last_name' => $contact->last_name ?: '',
                        'email' => $contact->email ?: null,
                        'mobile' => $contact->phone ?: null,
                    ]);

                    $clientRel = PartyRelationship::query()
                        ->where('party_id', $partyUuid)
                        ->where('relationship_type', 'client')
                        ->first();

                    ContactAssignment::create([
                        'person_id' => $personParty->person->id,
                        'party_id' => $partyUuid,
                        'party_relationship_id' => $clientRel?->id,
                        'role' => 'contact',
                        'receives_invoices' => (bool) $contact->send_email,
                        'is_primary' => (bool) $contact->is_primary,
                        'is_active' => true,
                    ]);
                });

                $this->created++;
            } catch (Throwable $e) {
                $this->error("    Error: {$e->getMessage()}");
                Log::error("ninja:import contacts — #{$contact->id}: {$e->getMessage()}");
                $this->errored++;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Phase: Invoices
    // -------------------------------------------------------------------------

    private function importInvoices(bool $dry, int $limit): void
    {
        $query = $this->ninja('invoices')
            ->where('is_deleted', 0)
            ->whereIn('status_id', [1, 2, 4])
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->cursor() as $inv) {
            $partyUuid = $this->clientMap[$inv->client_id] ?? null;

            if (! $partyUuid) {
                $this->warn("  Invoice #{$inv->id} ({$inv->number}): client #{$inv->client_id} not mapped — skip");
                $this->skipped++;

                continue;
            }

            $this->line("  Invoice #{$inv->id}: {$inv->number}");

            $existing = Document::query()
                ->where('document_type', 'sales_invoice')
                ->whereJsonContains('metadata->ninja_id', $inv->id)
                ->first();

            if ($existing) {
                $this->invoiceMap[$inv->id] = $existing->id;
                $this->skipped++;

                continue;
            }

            if ($dry) {
                $this->created++;

                continue;
            }

            try {
                DB::transaction(function () use ($inv, $partyUuid) {
                    DocumentLine::$recalculatesDocumentTotals = false;

                    try {
                        $doc = Document::create([
                            'document_type' => 'sales_invoice',
                            'direction' => 'outbound',
                            'document_number' => $inv->number,
                            'status' => $this->mapInvoiceStatus((int) $inv->status_id),
                            'party_id' => $partyUuid,
                            'issue_date' => $inv->date ?: now()->toDateString(),
                            'due_date' => $inv->due_date ?: null,
                            'currency' => 'ZAR',
                            'subtotal' => 0,
                            'tax_total' => 0,
                            'total' => 0,
                            'amount_paid' => 0,
                            'balance_due' => 0,
                            'notes' => $inv->public_notes ?: null,
                            'terms' => $inv->terms ?: null,
                            'footer' => $inv->footer ?: null,
                            'receivable_account_id' => $this->receivableAccountId,
                            'source' => 'import',
                            'metadata' => ['ninja_id' => $inv->id],
                        ]);

                        $this->createDocumentLines($doc->id, $inv->line_items, (bool) $inv->uses_inclusive_taxes);
                        $doc->recalculateTotals();

                        $this->invoiceMap[$inv->id] = $doc->id;
                    } finally {
                        DocumentLine::$recalculatesDocumentTotals = true;
                    }
                });

                $this->created++;
            } catch (Throwable $e) {
                $this->error("    Error: {$e->getMessage()}");
                Log::error("ninja:import invoices — #{$inv->id}: {$e->getMessage()}");
                $this->errored++;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Phase: Quotes
    // -------------------------------------------------------------------------

    private function importQuotes(bool $dry, int $limit): void
    {
        $dummyMap = [];
        $this->importDocumentType('quotes', 'quote', $dry, $limit, $dummyMap);
    }

    // -------------------------------------------------------------------------
    // Phase: Credits
    // -------------------------------------------------------------------------

    private function importCredits(bool $dry, int $limit): void
    {
        $this->importDocumentType('credits', 'credit_note', $dry, $limit, $this->creditMap);
    }

    // -------------------------------------------------------------------------
    // Shared document importer (quotes + credits)
    // -------------------------------------------------------------------------

    /**
     * @param  array<int, string>  $map  Populated with ninjaId → documentUuid
     */
    private function importDocumentType(
        string $ninjaTable,
        string $merlinType,
        bool $dry,
        int $limit,
        array &$map,
    ): void {
        $query = $this->ninja($ninjaTable)
            ->where('is_deleted', 0)
            ->where('status_id', '!=', 5)
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->cursor() as $row) {
            $partyUuid = $this->clientMap[$row->client_id] ?? null;

            if (! $partyUuid) {
                $this->warn("  {$ninjaTable} #{$row->id}: client not mapped — skip");
                $this->skipped++;

                continue;
            }

            $this->line("  {$ninjaTable} #{$row->id}: {$row->number}");

            $existing = Document::query()
                ->where('document_type', $merlinType)
                ->whereJsonContains('metadata->ninja_id', $row->id)
                ->first();

            if ($existing) {
                $map[$row->id] = $existing->id;
                $this->skipped++;

                continue;
            }

            if ($dry) {
                $this->created++;

                continue;
            }

            try {
                DB::transaction(function () use ($row, $partyUuid, $merlinType, &$map) {
                    DocumentLine::$recalculatesDocumentTotals = false;

                    try {
                        $doc = Document::create([
                            'document_type' => $merlinType,
                            'direction' => 'outbound',
                            'document_number' => $row->number,
                            'status' => 'draft',
                            'party_id' => $partyUuid,
                            'issue_date' => $row->date ?: now()->toDateString(),
                            'due_date' => $row->due_date ?: null,
                            'currency' => 'ZAR',
                            'subtotal' => 0,
                            'tax_total' => 0,
                            'total' => 0,
                            'amount_paid' => 0,
                            'balance_due' => 0,
                            'notes' => $row->public_notes ?: null,
                            'terms' => $row->terms ?: null,
                            'footer' => $row->footer ?: null,
                            'receivable_account_id' => $this->receivableAccountId,
                            'source' => 'import',
                            'metadata' => ['ninja_id' => $row->id],
                        ]);

                        $this->createDocumentLines($doc->id, $row->line_items, (bool) ($row->uses_inclusive_taxes ?? false));
                        $doc->recalculateTotals();

                        $map[$row->id] = $doc->id;
                    } finally {
                        DocumentLine::$recalculatesDocumentTotals = true;
                    }
                });

                $this->created++;
            } catch (Throwable $e) {
                $this->error("    Error: {$e->getMessage()}");
                Log::error("ninja:import {$ninjaTable} — #{$row->id}: {$e->getMessage()}");
                $this->errored++;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Phase: Payments
    // -------------------------------------------------------------------------

    private function importPayments(DocumentService $documentService, bool $dry, int $limit): void
    {
        $query = $this->ninja('payments')
            ->where('is_deleted', 0)
            ->where('status_id', 4)
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->cursor() as $payment) {
            $this->line("  Payment #{$payment->id}: {$payment->number}");

            $existing = Document::query()
                ->where('document_type', 'payment')
                ->whereJsonContains('metadata->ninja_id', $payment->id)
                ->first();

            if ($existing) {
                $this->skipped++;

                continue;
            }

            if ($dry) {
                $this->created++;

                continue;
            }

            if ((float) $payment->amount <= 0) {
                $this->warn("    Payment #{$payment->id}: zero amount — skip");
                $this->skipped++;

                continue;
            }

            $partyUuid = $this->clientMap[$payment->client_id] ?? null;

            if (! $partyUuid) {
                $this->warn("    Payment #{$payment->id}: client not mapped — skip");
                $this->skipped++;

                continue;
            }

            try {
                DB::transaction(function () use ($payment, $partyUuid, $documentService) {
                    $amount = (float) $payment->amount;
                    $date = Carbon::parse($payment->date);
                    $reference = $payment->number ?: $payment->transaction_reference ?: null;

                    $paymentDoc = Document::create([
                        'document_type' => 'payment',
                        'direction' => 'inbound',
                        'status' => 'posted',
                        'party_id' => $partyUuid,
                        'issue_date' => $date->toDateString(),
                        'reference' => $reference,
                        'currency' => 'ZAR',
                        'subtotal' => $amount,
                        'tax_total' => 0,
                        'total' => $amount,
                        'amount_paid' => 0,
                        'balance_due' => 0,
                        'contra_account_id' => $this->bankAccountId,
                        'receivable_account_id' => $this->receivableAccountId,
                        'source' => 'import',
                        'metadata' => ['ninja_id' => $payment->id],
                    ]);

                    $allocations = $this->ninja('paymentables')
                        ->where('payment_id', $payment->id)
                        ->whereNull('deleted_at')
                        ->get();

                    foreach ($allocations as $alloc) {
                        $allocAmount = (float) $alloc->amount;

                        if ($alloc->paymentable_type === 'invoices') {
                            $invoiceUuid = $this->invoiceMap[$alloc->paymentable_id] ?? null;

                            if (! $invoiceUuid) {
                                $this->warn("      Allocation to invoice #{$alloc->paymentable_id} not in map — skip");

                                continue;
                            }

                            $invoice = Document::find($invoiceUuid);

                            if (! $invoice) {
                                continue;
                            }

                            DocumentRelationship::create([
                                'parent_document_id' => $invoice->id,
                                'child_document_id' => $paymentDoc->id,
                                'relationship_type' => 'payment_for',
                            ]);

                            try {
                                $documentService->recordPayment($invoice, $allocAmount, $date, $reference);
                            } catch (\InvalidArgumentException $e) {
                                // Tolerate overpayment due to rounding; cap at balance_due.
                                $balance = (float) $invoice->balance_due;

                                if ($balance > 0 && $allocAmount > $balance) {
                                    $this->warn("      Payment #{$payment->id} exceeds balance on invoice #{$invoice->document_number} ({$allocAmount} > {$balance}); capping.");
                                    $documentService->recordPayment($invoice->fresh(), $balance, $date, $reference);
                                } else {
                                    throw $e;
                                }
                            }
                        } elseif (str_contains((string) $alloc->paymentable_type, 'Credit')) {
                            // Credit allocations are informational — no journal entry in Merlin
                            $this->line("      Credit #{$alloc->paymentable_id} allocation noted (skipped — no Merlin representation).");
                        }
                    }
                });

                $this->created++;
            } catch (Throwable $e) {
                $this->error("    Error: {$e->getMessage()}");
                Log::error("ninja:import payments — #{$payment->id}: {$e->getMessage()}");
                $this->errored++;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Phase: Recurring Invoices
    // -------------------------------------------------------------------------

    private function importRecurring(bool $dry, int $limit): void
    {
        $query = $this->ninja('recurring_invoices')
            ->where('is_deleted', 0)
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->cursor() as $rec) {
            $partyUuid = $this->clientMap[$rec->client_id] ?? null;

            if (! $partyUuid) {
                $this->warn("  Recurring #{$rec->id}: client not mapped — skip");
                $this->skipped++;

                continue;
            }

            $this->line("  Recurring #{$rec->id}: freq {$rec->frequency_id}, client #{$rec->client_id}");

            $frequency = $this->mapRecurringFrequency((int) $rec->frequency_id);

            if ($frequency === null) {
                $this->warn("  Recurring #{$rec->id}: unsupported frequency_id {$rec->frequency_id} — skip");
                $this->skipped++;

                continue;
            }

            $sentinel = "[ninja_id:{$rec->id}]";

            $exists = RecurringInvoice::query()
                ->where('client_id', $partyUuid)
                ->where('notes', 'like', "%{$sentinel}%")
                ->exists();

            if ($exists) {
                $this->skipped++;

                continue;
            }

            if ($dry) {
                $this->created++;

                continue;
            }

            try {
                DB::transaction(function () use ($rec, $partyUuid, $frequency, $sentinel) {
                    $status = $this->mapRecurringStatus((int) $rec->status_id);
                    $notes = trim(($rec->public_notes ?? '')."\n{$sentinel}");
                    $nextDate = $rec->next_send_date
                        ? Carbon::parse($rec->next_send_date)->toDateString()
                        : now()->toDateString();

                    $recurring = RecurringInvoice::create([
                        'client_id' => $partyUuid,
                        'frequency' => $frequency,
                        'status' => $status,
                        'start_date' => $rec->date ?: now()->toDateString(),
                        'next_invoice_date' => $nextDate,
                        'next_period_anchor' => $nextDate,
                        'billing_period_day' => (int) Carbon::parse($nextDate)->day,
                        'receivable_account_id' => $this->receivableAccountId,
                        'currency' => 'ZAR',
                        'auto_send' => false,
                        'notes' => $notes,
                        'terms' => $rec->terms ?: null,
                        'footer' => $rec->footer ?: null,
                    ]);

                    $lineItems = $this->parseJson($rec->line_items) ?? [];
                    $lineNum = 1;

                    foreach ($lineItems as $item) {
                        $item = (object) $item;
                        RecurringInvoiceLine::create([
                            'recurring_invoice_id' => $recurring->id,
                            'line_number' => $lineNum++,
                            'description' => $this->buildLineDescription($item),
                            'account_id' => $this->revenueAccountId,
                            'quantity' => (float) ($item->quantity ?? 1),
                            'unit_price' => (float) ($item->cost ?? 0),
                            'tax_rate' => ((float) ($item->tax_rate1 ?? 0)) ?: null,
                        ]);
                    }
                });

                $this->created++;
            } catch (Throwable $e) {
                $this->error("    Error: {$e->getMessage()}");
                Log::error("ninja:import recurring — #{$rec->id}: {$e->getMessage()}");
                $this->errored++;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createDocumentLines(string $documentId, ?string $lineItemsJson, bool $inclusiveTax): void
    {
        $lineItems = $this->parseJson($lineItemsJson) ?? [];
        $lineNum = 1;

        foreach ($lineItems as $item) {
            $item = (object) $item;
            $cost = (float) ($item->cost ?? 0);
            $qty = (float) ($item->quantity ?? 1);
            $taxRate = (float) ($item->tax_rate1 ?? 0);

            $line = new DocumentLine([
                'document_id' => $documentId,
                'line_number' => $lineNum++,
                'type' => 'service',
                'description' => $this->buildLineDescription($item),
                'account_id' => $this->revenueAccountId,
                'quantity' => $qty,
                'unit_price' => $cost,
                'tax_rate' => $taxRate ?: null,
            ]);

            if ($inclusiveTax && $taxRate > 0) {
                $gross = round($cost * $qty, 2);
                $net = round($gross / (1 + $taxRate / 100), 2);
                $line->unit_price = round($net / max($qty, 0.0001), 4);
                $line->taxAmountOverride = $gross - $net;
            }

            $line->save();
        }
    }

    private function buildLineDescription(object $item): string
    {
        $key = trim((string) ($item->product_key ?? ''));
        $notes = trim((string) ($item->notes ?? ''));

        if ($key && $notes) {
            return "{$key}: {$notes}";
        }

        return $key ?: $notes ?: '';
    }

    private function mapInvoiceStatus(int $ninjaStatus): string
    {
        return match ($ninjaStatus) {
            1 => 'draft',
            2, 4 => 'sent', // payments drive status 4 invoices to paid/partially_paid
            default => 'draft',
        };
    }

    private function mapRecurringFrequency(int $freqId): ?RecurringFrequency
    {
        $direct = [
            2 => RecurringFrequency::Weekly,
            4 => RecurringFrequency::Fortnightly,
            5 => RecurringFrequency::Monthly,
            7 => RecurringFrequency::Quarterly,
            10 => RecurringFrequency::Annually,
        ];

        if (isset($direct[$freqId])) {
            return $direct[$freqId];
        }

        // Round unsupported frequencies to nearest supported case.
        $fallback = match ($freqId) {
            1 => RecurringFrequency::Weekly,
            3 => RecurringFrequency::Monthly,
            6 => RecurringFrequency::Monthly,
            8 => RecurringFrequency::Quarterly,
            9 => RecurringFrequency::Quarterly,
            11 => RecurringFrequency::Annually,
            default => null,
        };

        if ($fallback !== null) {
            $this->warn("  Frequency {$freqId} has no exact match — using {$fallback->value}");
        }

        return $fallback;
    }

    private function mapRecurringStatus(int $statusId): RecurringInvoiceStatus
    {
        return match ($statusId) {
            2 => RecurringInvoiceStatus::Active,
            3 => RecurringInvoiceStatus::Paused,
            4 => RecurringInvoiceStatus::Completed,
            default => RecurringInvoiceStatus::Paused,
        };
    }

    private function configureNinjaConnection(string $path): void
    {
        $env = $this->parseNinjaEnv("{$path}/.env");
        $driver = $env['DB_CONNECTION'] ?? 'mysql';

        $config = match ($driver) {
            'sqlite' => [
                'driver' => 'sqlite',
                'database' => $env['DB_DATABASE'],
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            default => [
                'driver' => 'mysql',
                'host' => $env['DB_HOST'] ?? '127.0.0.1',
                'port' => $env['DB_PORT'] ?? '3306',
                'database' => $env['DB_DATABASE'],
                'username' => $env['DB_USERNAME'] ?? '',
                'password' => $env['DB_PASSWORD'] ?? '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => false,
            ],
        };

        Config::set('database.connections.ninja', $config);
        DB::purge('ninja');
    }

    /** @return array<string, string> */
    private function parseNinjaEnv(string $envPath): array
    {
        $result = [];

        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $result[trim($key)] = trim($value, " \t\"'");
        }

        return $result;
    }

    private function ninja(string $table): Builder
    {
        return DB::connection('ninja')->table($table);
    }

    private function loadAccountIds(): bool
    {
        $revenue = Account::where('code', '4000')->first();
        $receivable = Account::where('code', '1100')->first();
        $bank = Account::where('code', '1000')->first();

        $missing = collect(['4000' => $revenue, '1100' => $receivable, '1000' => $bank])
            ->filter(fn ($v) => $v === null)
            ->keys()
            ->implode(', ');

        if ($missing) {
            $this->error("Missing required accounts: {$missing}. Run ChartOfAccountsSeeder first.");

            return false;
        }

        $this->revenueAccountId = $revenue->id;
        $this->receivableAccountId = $receivable->id;
        $this->bankAccountId = $bank->id;

        return true;
    }

    private function loadClientMap(): void
    {
        PartyRelationship::query()
            ->where('relationship_type', 'client')
            ->whereNotNull('metadata->ninja_id')
            ->get(['party_id', 'metadata'])
            ->each(function ($rel) {
                $ninjaId = $rel->metadata['ninja_id'] ?? null;

                if ($ninjaId !== null) {
                    $this->clientMap[$ninjaId] = $rel->party_id;
                }
            });

        $count = count($this->clientMap);
        $this->comment("  Loaded {$count} client mapping(s) from prior import.");
    }

    private function loadInvoiceAndCreditMaps(): void
    {
        Document::query()
            ->whereIn('document_type', ['sales_invoice', 'credit_note'])
            ->whereNotNull('metadata->ninja_id')
            ->get(['id', 'document_type', 'metadata'])
            ->each(function ($doc) {
                $ninjaId = $doc->metadata['ninja_id'] ?? null;

                if ($ninjaId === null) {
                    return;
                }

                if ($doc->document_type === 'sales_invoice') {
                    $this->invoiceMap[$ninjaId] = $doc->id;
                } else {
                    $this->creditMap[$ninjaId] = $doc->id;
                }
            });

        $iCount = count($this->invoiceMap);
        $cCount = count($this->creditMap);
        $this->comment("  Loaded {$iCount} invoice + {$cCount} credit mapping(s) from prior import.");
    }

    private function ninjaCurrencyCode(mixed $currencyId): ?string
    {
        return match ((int) $currencyId) {
            1 => 'USD',
            2 => 'GBP',
            3 => 'ZAR',
            5 => 'EUR',
            12 => 'AUD',
            default => null,
        };
    }

    private function ninjaCountryCode(int $countryId): string
    {
        return match ($countryId) {
            516 => 'NA',
            710 => 'ZA',
            840 => 'US',
            826 => 'GB',
            276 => 'DE',
            default => 'ZA', // all clients in this dataset are South African
        };
    }

    private function parseJson(?string $json): mixed
    {
        if (! $json) {
            return null;
        }

        return json_decode($json, associative: true);
    }

    private function resetCounters(): void
    {
        $this->created = 0;
        $this->skipped = 0;
        $this->errored = 0;
    }

    /** @param array<string, array{created: int, skipped: int, errors: int}> $summary */
    private function printSummaryTable(array $summary): void
    {
        $this->newLine();
        $this->info('=== Import Summary ===');

        $this->table(
            ['Phase', 'Created', 'Skipped', 'Errors'],
            array_map(
                fn (string $phase, array $counts) => [
                    $phase,
                    $counts['created'],
                    $counts['skipped'],
                    $counts['errors'],
                ],
                array_keys($summary),
                $summary,
            ),
        );
    }
}
