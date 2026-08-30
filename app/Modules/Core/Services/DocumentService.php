<?php

namespace App\Modules\Core\Services;

use App\Exceptions\InvalidDocumentStateException;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\BankReconciliationMatch;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentActivity;
use App\Modules\Core\Models\DocumentLine;
use App\Modules\Core\Models\DocumentRelationship;
use App\Modules\Core\Models\User;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\Services\PaymentEvidenceRecorder;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DocumentService
{
    public function __construct(
        private readonly CurrencySettings $currencySettings,
        private readonly BillingSettings $billingSettings,
    ) {}

    // -------------------------------------------------------------------------
    // Status transitions
    // -------------------------------------------------------------------------

    public function markAsSent(Document $doc, ?User $by): void
    {
        DB::transaction(function () use ($doc, $by) {
            $this->transition($doc, 'sent', $by, 'Invoice sent to client.');
            $this->stampTaxAccount($doc);
        });
    }

    /**
     * Stamp the VAT liability account onto an invoice at the moment it's
     * issued, rather than have the postings view read
     * BillingSettings::tax_liability_account_id live — a view can't reach a
     * settings-table JSON payload portably, and reading it live would let a
     * later change to the setting retroactively re-post a historical
     * invoice's VAT leg. Once stamped, the postings view picks it straight
     * off the row; nothing else needs to happen at write time — an invoice
     * with an uncoded line or no receivable account simply doesn't appear
     * in the view yet, the same silent omission the old per-report
     * whereNotNull() guards always had, just moved from report-query time
     * to (nowhere — it's structural now).
     */
    private function stampTaxAccount(Document $doc): void
    {
        // Mirrors postSalesInvoiceJournal()'s old guard order: the VAT
        // account is only a hard requirement once the invoice is otherwise
        // postable at all. Without receivable_account_id it won't appear in
        // the postings view regardless (see the create_postings_view
        // migration), so it's still an in-progress document, not the
        // "configured VAT but no VAT account" Settings gap this throws for.
        if ((float) $doc->tax_total <= 0 || $doc->receivable_account_id === null) {
            return;
        }

        $taxAccountId = $this->billingSettings->tax_liability_account_id;

        if ($taxAccountId === null) {
            throw new \RuntimeException("Cannot send invoice {$doc->document_number}: it carries VAT but Settings > Billing has no VAT liability account configured.");
        }

        $doc->tax_account_id = $taxAccountId;
        $doc->saveQuietly();
    }

    public function recordResend(Document $doc, ?User $by): void
    {
        $this->recordActivity($doc, $by, 'resent', 'Invoice resent to client.');
    }

    /**
     * An issued sales invoice can never be voided — the correct reversal is
     * a credit note, dated in the period the mistake is discovered rather
     * than retroactively erasing the period the invoice was issued in. This
     * creates a draft credit note pre-filled from the invoice (party,
     * currency, and a like-for-like copy of every line) so the operator can
     * issue it as-is for a full credit, or trim/edit lines first for a
     * partial one. Applying it to the invoice is a separate, existing step
     * (applyCreditNote()) — this only prepares the draft.
     */
    public function createCreditNoteFromInvoice(Document $invoice, User $by): Document
    {
        return DB::transaction(function () use ($invoice, $by): Document {
            $creditNote = Document::create([
                'document_type' => 'credit_note',
                'direction' => 'outbound',
                'status' => 'draft',
                'party_id' => $invoice->party_id,
                'issue_date' => now()->toDateString(),
                'reference' => "Credit for {$invoice->document_number}",
                'currency' => $invoice->currency,
                'exchange_rate' => $invoice->exchange_rate,
                'source' => 'manual',
            ]);

            foreach ($invoice->lines as $line) {
                $creditNote->lines()->create([
                    'line_number' => $line->line_number,
                    'type' => $line->type,
                    'description' => $line->description,
                    'account_id' => $line->account_id,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'discount_percent' => $line->discount_percent,
                    'discount_amount' => $line->discount_amount,
                    'tax_rate' => $line->tax_rate,
                ]);
            }

            $creditNote->recalculateTotals();
            $this->recordActivity($creditNote, $by, 'created', "Drafted from invoice {$invoice->document_number}.");

            return $creditNote;
        });
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
        // Dated reversal is the entire point of crediting rather than voiding
        // — a credit note that predates the invoice it credits would put the
        // reversal in the wrong period, exactly the defect this replaces.
        if ($creditNote->issue_date !== null && $invoice->issue_date !== null
            && $creditNote->issue_date->lt($invoice->issue_date)) {
            throw new \InvalidArgumentException(
                "Credit note {$creditNote->document_number} is dated before invoice {$invoice->document_number}; it cannot be applied."
            );
        }

        DB::transaction(function () use ($creditNote, $invoice, $by) {
            // credits_applied accumulates the raw credit note total, uncapped
            // — recalculateBalance()'s max(0, ...) floors the displayed
            // balance_due at zero on its own for a credit note larger than
            // what remains owed, mirroring the pre-existing floor-at-zero
            // behaviour without needing to cap the stored credit itself.
            $amount = (float) $creditNote->total;

            $invoice->credits_applied = (float) $invoice->credits_applied + $amount;
            $invoice->recalculateBalance();

            if (in_array($invoice->status, ['sent', 'partially_paid'], true)) {
                $invoice->status = (float) $invoice->balance_due <= 0 ? 'paid' : 'partially_paid';
            }

            $invoice->saveQuietly();

            $this->linkDocuments($invoice, $creditNote, 'credited_by');
            $this->transition($creditNote, 'applied', $by, "Applied to invoice {$invoice->document_number}.");
            $this->recordActivity($invoice, $by, 'credit_applied', "Credit note {$creditNote->document_number} applied; balance reduced by {$creditNote->currency} {$amount}.");

            // The postings view's credit-note branch reads receivable_account_id
            // straight off the credit note row (same as every other document
            // type) — a credit note has no AR account of its own at creation, so
            // stamp the invoice's onto it here, at the one moment that
            // relationship becomes fixed. Matches what postCreditNoteJournal()
            // used to read from $invoice transiently, just persisted instead.
            $creditNote->receivable_account_id ??= $invoice->receivable_account_id;
            $creditNote->saveQuietly();
        });
    }

    public function markAsReviewed(Document $doc, User $by): void
    {
        $this->transition($doc, 'reviewed', $by, 'Marked as reviewed.');
    }

    /**
     * Close a bank/credit-card statement out once its lines are reconciled.
     * Never posts anything — statements are evidence, not a posting source.
     */
    public function markStatementReconciled(Document $doc, User $by): void
    {
        $this->transition($doc, 'reconciled', $by, 'Reconciliation complete.');
    }

    public function approve(Document $doc, User $by): void
    {
        $this->transition($doc, 'approved', $by, 'Approved for payment.');
    }

    /**
     * A posted purchase invoice needs no stamping beyond the status change —
     * its payable account and each line's expense account are already their
     * own columns, which is all the postings view reads. An invoice with an
     * uncoded line or no payable account simply doesn't appear in the view
     * yet, same as the sales side.
     */
    public function post(Document $doc, User $by): void
    {
        $this->transition($doc, 'posted', $by, 'Posted to the general ledger.');
        $this->recordPendingPurchasePayment($doc);
    }

    /**
     * Record that a bank/credit-card statement line has been reconciled
     * against a document — an existing payment recorded before the
     * statement arrived, in the common case, or one just created for this
     * line (see reconcileToGlAccount()). No posting happens here: the
     * matched document is assumed already posted, or was posted by whatever
     * created it. This method only records the link. updateOrCreate() so
     * re-confirming (or correcting) a match is idempotent.
     */
    public function matchReconciliation(DocumentLine $statementLine, Document $document, User $by): BankReconciliationMatch
    {
        if (! in_array($statementLine->document->document_type, ['bank_statement', 'credit_card_statement'], true)) {
            throw new \InvalidArgumentException('matchReconciliation only accepts lines on a bank or credit card statement.');
        }

        return BankReconciliationMatch::updateOrCreate(
            ['statement_line_id' => $statementLine->id],
            ['document_id' => $document->id, 'matched_by' => $by->id, 'matched_at' => now()],
        );
    }

    public function unmatchReconciliation(DocumentLine $statementLine): void
    {
        BankReconciliationMatch::where('statement_line_id', $statementLine->id)->delete();
    }

    /**
     * For a statement line with no corresponding document at all — a bank
     * charge, interest, or anything else nobody recorded ahead of time —
     * create a payment Document as the housing record and post a plain
     * two-line entry directly (this account vs the statement's bank
     * account), then mark the line reconciled against it. Bypasses
     * postPaymentJournal() because that method requires a receivable or
     * payable account; a bank charge has neither.
     */
    public function reconcileToGlAccount(DocumentLine $statementLine, string $glAccountId, User $by): Document
    {
        $statement = $statementLine->document;
        $amount = abs((float) $statementLine->unit_price);
        $direction = (float) $statementLine->unit_price >= 0 ? 'inbound' : 'outbound';
        $date = isset($statementLine->metadata['transaction_date'])
            ? Carbon::parse($statementLine->metadata['transaction_date'])
            : ($statement->issue_date ?? now());

        return DB::transaction(function () use ($statementLine, $statement, $glAccountId, $amount, $direction, $date, $by): Document {
            // The postings view's payment branch already reads
            // receivable_account_id (inbound) / payable_account_id (outbound)
            // against contra_account_id — a bank charge's expense account
            // fills the exact same slot an AR/AP account would on an
            // ordinary payment, so this needs no branch of its own.
            $entry = Document::create([
                'document_type' => 'payment',
                'direction' => $direction,
                'status' => 'posted',
                'issue_date' => $date->toDateString(),
                'currency' => $statement->currency,
                'exchange_rate' => 1.0,
                'subtotal' => $amount,
                'tax_total' => 0,
                'total' => $amount,
                'receivable_account_id' => $direction === 'inbound' ? $glAccountId : null,
                'payable_account_id' => $direction === 'outbound' ? $glAccountId : null,
                'contra_account_id' => $statement->contra_account_id,
                'reference' => $statementLine->description,
                'source' => 'reconciliation',
            ]);

            $this->matchReconciliation($statementLine, $entry, $by);

            return $entry;
        });
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

            // Reject overpayment against what's actually still owed (total
            // less amount already paid AND any credit notes applied). The
            // 1-cent epsilon tolerates FX rounding when the rate is
            // finalised from the actual amount paid.
            $remainingBeforePayment = (float) $doc->total - (float) $doc->amount_paid - (float) $doc->credits_applied;

            if ($amount - $remainingBeforePayment > 0.01) {
                throw new \InvalidArgumentException(sprintf(
                    'Payment of %.2f exceeds the balance due of %.2f.',
                    $amount,
                    max(0, $remainingBeforePayment),
                ));
            }

            $doc->amount_paid = $newAmountPaid;
            $doc->recalculateBalance();
            $newBalanceDue = (float) $doc->balance_due;

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
     * @param  array{amount: float, date: string, reference?: string|null, finalise_rate?: bool, contra_account_id?: string|null}  $data
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
                'payable_account_id' => $invoice->payable_account_id,
                'contra_account_id' => $data['contra_account_id'] ?? null,
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
        $this->recordPendingPurchasePayment($doc);
    }

    /**
     * A purchase invoice can reach 'posted' status after payment evidence (a
     * bank/gateway notification or a paid receipt) already arrived while it
     * was still under review — that evidence gets stashed as pending_payment
     * metadata instead of a GL entry, since there's no ledger row to post
     * against yet. Once posted, replay it through the same dedup-guarded
     * recorder used for evidence that arrives after posting.
     */
    private function recordPendingPurchasePayment(Document $doc): void
    {
        if ($doc->document_type !== 'purchase_invoice') {
            return;
        }

        $pending = $doc->metadata['pending_payment'] ?? null;

        if ($pending === null) {
            return;
        }

        $metadata = $doc->metadata;
        unset($metadata['pending_payment']);
        $doc->update(['metadata' => $metadata ?: null]);

        app(PaymentEvidenceRecorder::class)->record(
            $doc,
            (float) $pending['amount'],
            Carbon::parse($pending['date']),
            $pending['reference'] ?? null,
            $pending['evidence_source'] ?? 'pending payment',
        );
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
                'queued' => ['received', 'rejected'],
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
                'draft' => ['sent'],
                'sent' => ['partially_paid', 'paid'],
                'partially_paid' => ['paid'],
                'paid' => [],
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
                'draft' => ['issued'],
                'issued' => ['applied'],
                'applied' => [],
            ],
            // Statements never post to the ledger — they're reconciliation
            // evidence. 'reconciled' just closes the statement out once its
            // lines are matched; nothing in the journal depends on it.
            'bank_statement' => [
                'queued' => ['received'],
                'received' => ['reviewed', 'reconciled'],
                'reviewed' => ['reconciled'],
                'reconciled' => [],
            ],
            'credit_card_statement' => [
                'queued' => ['received'],
                'received' => ['reviewed', 'reconciled'],
                'reviewed' => ['reconciled'],
                'reconciled' => [],
            ],
        ];
    }
}
