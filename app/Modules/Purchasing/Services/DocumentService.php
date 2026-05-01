<?php

namespace App\Modules\Purchasing\Services;

use App\Exceptions\InvalidDocumentStateException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\Jobs\ProcessInvoiceDocument;
use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Models\DocumentActivity;
use App\Modules\Purchasing\Models\DocumentRelationship;
use App\Modules\Purchasing\Services\Pdf\MagikaService;
use App\Modules\Purchasing\Settings\PurchasingSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class DocumentService
{
    public function __construct(
        private readonly CurrencySettings $currencySettings,
        private readonly PurchasingSettings $purchasingSettings,
        private readonly MagikaService $magika,
    ) {}

    // -------------------------------------------------------------------------
    // Status transitions
    // -------------------------------------------------------------------------

    public function markAsSent(Document $doc, User $by): void
    {
        $this->transition($doc, 'sent', $by, 'Invoice sent to client.');
    }

    public function voidDocument(Document $doc, User $by): void
    {
        $this->transition($doc, 'voided', $by, 'Invoice voided.');
    }

    public function markAsReviewed(Document $doc, User $by): void
    {
        $this->transition($doc, 'reviewed', $by, 'Marked as reviewed.');
    }

    public function approve(Document $doc, User $by): void
    {
        $this->transition($doc, 'approved', $by, 'Approved for payment.');
    }

    public function post(Document $doc, User $by): void
    {
        $this->transition($doc, 'posted', $by, 'Posted to the general ledger.');
    }

    public function dispute(Document $doc, User $by, string $reason): void
    {
        $this->transition($doc, 'disputed', $by, "Disputed: {$reason}");
    }

    public function reject(Document $doc, User $by, string $reason): void
    {
        $this->transition($doc, 'rejected', $by, "Rejected: {$reason}");
    }

    // -------------------------------------------------------------------------
    // Operations
    // -------------------------------------------------------------------------

    /**
     * Create a Document from an uploaded invoice file (PDF, DOCX, XLSX, or CSV).
     *
     * Returns the document and a flag indicating whether the file was a
     * duplicate of an already-uploaded invoice.
     *
     * @param  array<string, mixed>  $data  Accepted keys: party_id (nullable), currency
     * @return array{document: Document, duplicate: bool}
     */
    public function createFromFile(string $absolutePath, array $data): array
    {
        $this->magika->assertIsSupportedFormat($absolutePath);

        $hash = hash_file('sha256', $absolutePath);

        $existing = Media::where('collection_name', 'source_document')
            ->where('custom_properties->sha256', $hash)
            ->first();

        if ($existing !== null) {
            /** @var Document $document */
            $document = Document::findOrFail($existing->model_id);

            return ['document' => $document, 'duplicate' => true];
        }

        $currency = strtoupper($data['currency'] ?? $this->currencySettings->base_currency);

        $document = Document::create([
            'document_type' => 'purchase_invoice',
            'direction' => 'inbound',
            'status' => 'received',
            'currency' => $currency,
            'exchange_rate' => 1.0,
            'party_id' => $data['party_id'] ?? null,
            'source' => 'upload',
            'payable_account_id' => $this->resolvePayableAccountId($data['party_id'] ?? null),
        ]);

        $document
            ->addMedia($absolutePath)
            ->withCustomProperties(['sha256' => $hash])
            ->toMediaCollection('source_document');

        ProcessInvoiceDocument::dispatch($document);

        return ['document' => $document, 'duplicate' => false];
    }

    /**
     * Record a payment against a document.
     *
     * For foreign-currency invoices, pass $finaliseRate = true when the actual
     * ZAR amount paid is known. This recalculates the exchange rate from the
     * actual payment and updates all base-currency amounts on the document and
     * its lines to reflect the true cost. The rate is then marked non-provisional.
     */
    public function recordPayment(
        Document $doc,
        float $amount,
        CarbonInterface $date,
        ?string $reference = null,
        bool $finaliseRate = false,
    ): void {
        DB::transaction(function () use ($doc, $amount, $date, $reference, $finaliseRate) {

            if ($finaliseRate && $doc->is_foreign_currency && (float) $doc->foreign_total > 0) {
                $actualRate = round($amount / (float) $doc->foreign_total, 6);

                // Recompute base amounts on lines at the actual rate
                foreach ($doc->lines()->get() as $line) {
                    if ($line->foreign_line_total !== null) {
                        $line->unit_price = round((float) $line->foreign_unit_price * $actualRate, 4);
                        $line->line_total = round((float) $line->foreign_line_total * $actualRate, 2);
                        $line->tax_amount = round((float) $line->foreign_tax_amount * $actualRate, 2);
                        $line->saveQuietly();
                    }
                }

                // Recompute base amounts on document at the actual rate
                $doc->exchange_rate = $actualRate;
                $doc->exchange_rate_date = $date->toDateString();
                $doc->exchange_rate_provisional = false;
                $doc->subtotal = round((float) $doc->foreign_subtotal * $actualRate, 2);
                $doc->tax_total = round((float) $doc->foreign_tax_total * $actualRate, 2);
                $doc->total = round((float) $doc->foreign_total * $actualRate, 2);
            }

            $newAmountPaid = (float) $doc->amount_paid + $amount;
            $newBalanceDue = (float) $doc->total - $newAmountPaid;

            $doc->amount_paid = $newAmountPaid;
            $doc->balance_due = $newBalanceDue;

            if ($doc->is_foreign_currency && (float) $doc->exchange_rate > 0) {
                $foreignPaid = round($newAmountPaid / (float) $doc->exchange_rate, 2);
                $doc->foreign_amount_paid = $foreignPaid;
                $doc->foreign_balance_due = round((float) $doc->foreign_total - $foreignPaid, 2);
            }

            $doc->saveQuietly();

            $currency = $doc->currency ?? $this->currencySettings->base_currency;
            $description = $reference
                ? "Payment of {$currency} {$amount} recorded (ref: {$reference}) on {$date->toDateString()}."
                : "Payment of {$currency} {$amount} recorded on {$date->toDateString()}.";

            $this->recordActivity($doc, null, 'payment_recorded', $description, [
                'amount' => $amount,
                'currency' => $currency,
                'date' => $date->toDateString(),
                'reference' => $reference,
                'rate_finalised' => $finaliseRate,
            ]);
        });
    }

    public function deleteDocument(Document $doc, User $by): void
    {
        if ($doc->status === 'posted') {
            throw new \InvalidArgumentException('Cannot delete a posted invoice.');
        }

        DB::transaction(function () use ($doc, $by) {
            $this->recordActivity($doc, $by, 'deleted', 'Invoice deleted.');
            $doc->delete();
        });
    }

    public function reprocess(Document $doc, User $by): void
    {
        if (! in_array($doc->status, ['received', 'reviewed', 'rejected', 'disputed'])) {
            throw new \InvalidArgumentException("Cannot reprocess a {$doc->status} invoice.");
        }

        DB::transaction(function () use ($doc, $by) {
            $doc->lines()->delete();

            $doc->status = 'received';
            $doc->saveQuietly();

            $this->recordActivity($doc, $by, 'reprocess_queued', 'Invoice queued for reprocessing.');
        });

        ProcessInvoiceDocument::dispatch($doc);
    }

    public function duplicate(Document $doc): Document
    {
        return DB::transaction(function () use ($doc) {
            $newDoc = $doc->replicate(['document_number', 'amount_paid', 'balance_due', 'llm_confidence']);
            $newDoc->status = config("documents.types.{$doc->document_type}.default_status", 'received');
            $newDoc->source = 'manual';
            $newDoc->amount_paid = 0;
            $newDoc->balance_due = 0;
            $newDoc->issue_date = now();
            $newDoc->save();

            foreach ($doc->lines as $line) {
                $newLine = $line->replicate(['llm_account_suggestion', 'llm_confidence']);
                $newLine->document_id = $newDoc->id;
                $newLine->save();
            }

            $this->recordActivity($newDoc, null, 'created', "Duplicated from {$doc->document_number}.");

            return $newDoc;
        });
    }

    // -------------------------------------------------------------------------
    // Linking
    // -------------------------------------------------------------------------

    public function linkDocuments(Document $parent, Document $child, string $type): void
    {
        DocumentRelationship::firstOrCreate([
            'parent_document_id' => $parent->id,
            'child_document_id' => $child->id,
            'relationship_type' => $type,
        ]);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    public function markAsReviewedAutonomously(Document $doc, string $reason): void
    {
        $this->transition($doc, 'reviewed', null, "Auto-reviewed: {$reason}");
    }

    public function approveAutonomously(Document $doc, string $reason): void
    {
        $this->transition($doc, 'approved', null, "Auto-approved: {$reason}");
    }

    public function postAutonomously(Document $doc, string $reason): void
    {
        $this->transition($doc, 'posted', null, "Auto-posted: {$reason}");
    }

    private function resolvePayableAccountId(?string $partyId): ?string
    {
        if ($partyId !== null) {
            $override = Party::find($partyId)
                ?->relationships()
                ->where('relationship_type', 'supplier')
                ->first()
                ?->default_payable_account_id;

            if ($override !== null) {
                return $override;
            }
        }

        return Account::where('code', $this->purchasingSettings->default_payable_account)->value('id');
    }

    private function transition(Document $doc, string $to, ?User $by, string $description): void
    {
        $allowed = $this->getAllowedTransitions()[$doc->document_type][$doc->status] ?? [];

        if (! in_array($to, $allowed)) {
            throw InvalidDocumentStateException::transition($doc, $to);
        }

        DB::transaction(function () use ($doc, $to, $by, $description) {
            $from = $doc->status;

            $doc->status = $to;
            $doc->saveQuietly();

            $this->recordActivity($doc, $by, 'status_changed', $description, [
                'from' => $from,
                'to' => $to,
            ]);
        });
    }

    private function recordActivity(
        Document $doc,
        ?User $by,
        string $type,
        string $description,
        array $metadata = [],
    ): void {
        DocumentActivity::create([
            'document_id' => $doc->id,
            'user_id' => $by?->id,
            'activity_type' => $type,
            'description' => $description,
            'metadata' => $metadata ?: null,
        ]);
    }

    /** @return array<string, array<string, array<int, string>>> */
    private function getAllowedTransitions(): array
    {
        return [
            'purchase_invoice' => [
                'received' => ['reviewed', 'disputed', 'rejected'],
                'reviewed' => ['approved', 'posted', 'disputed'],
                'approved' => ['posted', 'disputed'],
                'disputed' => ['reviewed', 'rejected'],
                'posted' => [],
                'rejected' => [],
            ],
            'sales_invoice' => [
                'draft' => ['sent', 'voided'],
                'sent' => ['voided'],
                'voided' => [],
            ],
        ];
    }
}
