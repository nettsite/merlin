<?php

namespace App\Modules\Core\Services;

use App\Exceptions\InvalidDocumentStateException;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentActivity;
use App\Modules\Core\Models\DocumentRelationship;
use App\Modules\Core\Models\User;
use App\Modules\Core\Settings\CurrencySettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DocumentService
{
    public function __construct(
        private readonly CurrencySettings $currencySettings,
    ) {}

    // -------------------------------------------------------------------------
    // Status transitions
    // -------------------------------------------------------------------------

    public function markAsSent(Document $doc, ?User $by): void
    {
        $this->transition($doc, 'sent', $by, 'Invoice sent to client.');
    }

    public function recordResend(Document $doc, ?User $by): void
    {
        $this->recordActivity($doc, $by, 'resent', 'Invoice resent to client.');
    }

    public function voidDocument(Document $doc, User $by): void
    {
        $this->transition($doc, 'voided', $by, 'Invoice voided.');
    }

    public function sendQuote(Document $quote, ?User $by): void
    {
        $this->transition($quote, 'sent', $by, 'Quote sent to client.');
    }

    public function acceptQuote(Document $quote, ?User $by): void
    {
        $this->transition($quote, 'accepted', $by, 'Quote accepted by client.');
    }

    public function declineQuote(Document $quote, ?User $by): void
    {
        $this->transition($quote, 'declined', $by, 'Quote declined.');
    }

    public function expireQuote(Document $quote, ?User $by): void
    {
        $this->transition($quote, 'expired', $by, 'Quote expired.');
    }

    public function convertQuoteToInvoice(Document $quote, ?User $by): Document
    {
        return DB::transaction(function () use ($quote, $by) {
            $invoice = Document::create([
                'document_type' => 'sales_invoice',
                'direction' => 'outbound',
                'status' => 'draft',
                'party_id' => $quote->party_id,
                'reference' => $quote->reference,
                'issue_date' => now()->toDateString(),
                'currency' => $quote->currency,
                'exchange_rate' => $quote->exchange_rate ?? 1.0,
                'subtotal' => $quote->subtotal,
                'tax_total' => $quote->tax_total,
                'total' => $quote->total,
                'balance_due' => $quote->total,
                'payment_term_id' => $quote->payment_term_id,
                'notes' => $quote->notes,
                'source' => 'manual',
            ]);

            foreach ($quote->lines as $line) {
                $newLine = $line->replicate(['llm_account_suggestion', 'llm_confidence']);
                $newLine->document_id = $invoice->id;
                $newLine->save();
            }

            $this->linkDocuments($quote, $invoice, 'converted_from');
            $this->transition($quote, 'converted', $by, "Converted to invoice {$invoice->document_number}.");

            return $invoice;
        });
    }

    public function issueCreditNote(Document $creditNote, ?User $by): void
    {
        $this->transition($creditNote, 'issued', $by, 'Credit note issued.');
    }

    public function applyCreditNote(Document $creditNote, Document $invoice, ?User $by): void
    {
        DB::transaction(function () use ($creditNote, $invoice, $by) {
            $amount = (float) $creditNote->total;
            $newBalance = max(0, (float) $invoice->balance_due - $amount);

            $invoice->balance_due = $newBalance;
            $invoice->saveQuietly();

            $this->linkDocuments($invoice, $creditNote, 'credited_by');
            $this->transition($creditNote, 'applied', $by, "Applied to invoice {$invoice->document_number}.");
            $this->recordActivity($invoice, $by, 'credit_applied', "Credit note {$creditNote->document_number} applied; balance reduced by {$creditNote->currency} {$amount}.");
        });
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
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero.');
        }

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

            // Reject overpayment. The 1-cent epsilon tolerates FX rounding
            // when the rate is finalised from the actual amount paid.
            if ($newBalanceDue < -0.01) {
                throw new \InvalidArgumentException(sprintf(
                    'Payment of %.2f exceeds the balance due of %.2f.',
                    $amount,
                    (float) $doc->total - (float) $doc->amount_paid,
                ));
            }

            $doc->amount_paid = $newAmountPaid;
            $doc->balance_due = $newBalanceDue;

            if ($doc->is_foreign_currency && (float) $doc->exchange_rate > 0) {
                $foreignPaid = round($newAmountPaid / (float) $doc->exchange_rate, 2);
                $doc->foreign_amount_paid = $foreignPaid;
                $doc->foreign_balance_due = round((float) $doc->foreign_total - $foreignPaid, 2);
            }

            // Transition invoice status based on remaining balance.
            if ($doc->document_type === 'sales_invoice' && in_array($doc->status, ['sent', 'partially_paid'])) {
                $doc->status = $newBalanceDue <= 0 ? 'paid' : 'partially_paid';
            }

            if ($doc->document_type === 'purchase_invoice' && in_array($doc->status, ['posted', 'partially_paid'])) {
                $doc->status = $newBalanceDue <= 0 ? 'paid' : 'partially_paid';
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

    /**
     * Record a payment against a posted purchase invoice: creates an outbound
     * payment document linked via DocumentRelationship, then delegates
     * amount/balance/status updates to recordPayment().
     *
     * @param  array{amount: float, date: string, reference?: string|null, finalise_rate?: bool}  $data
     */
    public function recordPurchasePayment(Document $invoice, array $data, ?User $by): Document
    {
        if ($invoice->document_type !== 'purchase_invoice') {
            throw new \InvalidArgumentException('recordPurchasePayment only accepts purchase invoices.');
        }

        if (! in_array($invoice->status, ['posted', 'partially_paid'])) {
            throw new \InvalidArgumentException("Cannot record payment against a {$invoice->status} purchase invoice.");
        }

        $amount = (float) $data['amount'];
        $date = Carbon::parse($data['date']);
        $reference = $data['reference'] ?? null;

        return DB::transaction(function () use ($invoice, $amount, $date, $reference, $data) {
            $payment = Document::create([
                'document_type' => 'payment',
                'direction' => 'outbound',
                'status' => 'draft',
                'party_id' => $invoice->party_id,
                'issue_date' => $date->toDateString(),
                'currency' => $invoice->currency,
                'exchange_rate' => $invoice->exchange_rate ?? 1.0,
                'subtotal' => $amount,
                'tax_total' => 0,
                'total' => $amount,
                'source' => 'manual',
                'reference' => $reference,
            ]);

            DocumentRelationship::create([
                'parent_document_id' => $invoice->id,
                'child_document_id' => $payment->id,
                'relationship_type' => 'payment_for',
            ]);

            $this->recordPayment($invoice, $amount, $date, $reference, (bool) ($data['finalise_rate'] ?? false));

            return $payment;
        });
    }

    public function deleteDocument(Document $doc, User $by): void
    {
        if (in_array($doc->status, Document::POSTED_STATUSES)) {
            throw new \InvalidArgumentException('Cannot delete a posted invoice.');
        }

        DB::transaction(function () use ($doc, $by) {
            $this->recordActivity($doc, $by, 'deleted', 'Invoice deleted.');
            $doc->delete();
        });
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

    protected function transition(Document $doc, string $to, ?User $by, string $description): void
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

    protected function recordActivity(
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
                'received' => ['reviewed', 'approved', 'posted', 'disputed', 'rejected'],
                'reviewed' => ['approved', 'posted', 'disputed'],
                'approved' => ['posted', 'disputed'],
                'disputed' => ['reviewed', 'approved', 'posted', 'rejected'],
                // Payment states are set by recordPayment() based on balance,
                // mirroring the sales flow — listed here for documentation.
                'posted' => ['partially_paid', 'paid'],
                'partially_paid' => ['paid'],
                'paid' => [],
                'rejected' => [],
            ],
            'sales_invoice' => [
                'draft' => ['sent', 'voided'],
                'sent' => ['partially_paid', 'paid', 'voided'],
                'partially_paid' => ['paid', 'voided'],
                'paid' => [],
                'voided' => [],
            ],
            'quote' => [
                'draft' => ['sent', 'declined', 'expired'],
                'sent' => ['accepted', 'declined', 'expired'],
                'accepted' => ['converted'],
                'converted' => [],
                'declined' => [],
                'expired' => [],
            ],
            'credit_note' => [
                'draft' => ['issued', 'voided'],
                'issued' => ['applied', 'voided'],
                'applied' => [],
                'voided' => [],
            ],
        ];
    }
}
