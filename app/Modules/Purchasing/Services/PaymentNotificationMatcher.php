<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentLine;
use App\Modules\Core\Settings\CurrencySettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentNotificationMatcher
{
    private const MAX_DATE_WINDOW_DAYS = 30;

    public function __construct(
        private readonly CurrencySettings $currencySettings,
    ) {}

    /**
     * Search existing purchase invoices for a candidate the given payment
     * notification settles. Payment can't precede the purchase, so only
     * invoices issued on or before the payment date are considered.
     *
     * @return array{document: Document, confidence: float, reason: string}|null
     */
    public function findInvoiceMatch(Document $paymentNotification): ?array
    {
        $paymentDate = $paymentNotification->issue_date;

        if ($paymentDate === null) {
            return null;
        }

        $candidates = Document::purchaseInvoices()
            ->whereNotNull('issue_date')
            ->whereDate('issue_date', '<=', $paymentDate)
            ->whereDate('issue_date', '>=', $paymentDate->copy()->subDays(self::MAX_DATE_WINDOW_DAYS))
            ->with('party.business', 'party.person')
            ->get();

        return $this->best($candidates, fn (Document $invoice) => $this->score($invoice, $paymentNotification));
    }

    /**
     * Reciprocal check: search pending, unmatched payment notifications for
     * one the given (freshly processed) invoice settles.
     *
     * @return array{document: Document, confidence: float, reason: string}|null
     */
    public function findPaymentMatch(Document $invoice): ?array
    {
        $issueDate = $invoice->issue_date;

        if ($issueDate === null) {
            return null;
        }

        $candidates = Document::where('document_type', 'payment_notification')
            ->where('status', 'received')
            ->whereNotNull('issue_date')
            ->whereDate('issue_date', '>=', $issueDate)
            ->whereDate('issue_date', '<=', $issueDate->copy()->addDays(self::MAX_DATE_WINDOW_DAYS))
            ->get();

        return $this->best($candidates, fn (Document $paymentNotification) => $this->score($invoice, $paymentNotification));
    }

    /**
     * @param  Collection<int, Document>  $candidates
     * @param  \Closure(Document): (array{confidence: float, reason: string}|null)  $scorer
     * @return array{document: Document, confidence: float, reason: string}|null
     */
    private function best($candidates, \Closure $scorer): ?array
    {
        $best = null;

        foreach ($candidates as $candidate) {
            $result = $scorer($candidate);

            if ($result === null) {
                continue;
            }

            if ($best === null || $result['confidence'] > $best['confidence']) {
                $best = [...$result, 'document' => $candidate];
            }
        }

        return $best;
    }

    /** @return array{confidence: float, reason: string}|null */
    private function score(Document $invoice, Document $paymentNotification): ?array
    {
        $referenceText = (string) ($paymentNotification->metadata['reference_text'] ?? '');

        if ($invoice->document_number && $referenceText !== '' && stripos($referenceText, $invoice->document_number) !== false) {
            return ['confidence' => 0.95, 'reason' => "Reference \"{$referenceText}\" matches invoice {$invoice->document_number}"];
        }

        if ($invoice->reference && $referenceText !== '' && stripos($referenceText, (string) $invoice->reference) !== false) {
            return ['confidence' => 0.9, 'reason' => "Reference \"{$referenceText}\" matches supplier invoice number {$invoice->reference}"];
        }

        $payeeName = (string) ($paymentNotification->metadata['payee_name'] ?? '');
        $supplierName = $invoice->party?->displayName ?? '';

        if ($payeeName !== '' && $supplierName !== '' && $this->namesResemble($payeeName, $supplierName)) {
            return ['confidence' => 0.6, 'reason' => "Payee \"{$payeeName}\" resembles supplier \"{$supplierName}\"; dates align"];
        }

        if ($invoice->issue_date instanceof CarbonInterface && $invoice->issue_date->isSameDay($paymentNotification->issue_date)) {
            return ['confidence' => 0.4, 'reason' => 'Same-day match only — no reference or payee name confirmation'];
        }

        return null;
    }

    private function namesResemble(string $a, string $b): bool
    {
        similar_text(strtolower($a), strtolower($b), $percent);

        return $percent >= 50.0;
    }

    /**
     * Fold a matched payment notification into its invoice: move its media
     * across, correct the invoice's amount for a foreign-currency invoice
     * (the payment notification's local amount is more reliable than our
     * estimated exchange rate), log what happened, and discard the now-empty
     * payment notification Document.
     *
     * Financial correction is skipped for already-posted invoices (re-opening
     * posted GL entries is out of scope) and when the payment wasn't made in
     * the base currency (nothing reliable to correct against).
     */
    public function merge(Document $invoice, Document $paymentNotification, float $confidence, string $reason): void
    {
        DB::transaction(function () use ($invoice, $paymentNotification, $confidence, $reason) {
            $paymentNotification->getFirstMedia('source_document')?->move($invoice, 'attachments');

            $baseCurrency = strtoupper($this->currencySettings->base_currency);
            $paidCurrency = strtoupper((string) $paymentNotification->currency);

            // A merely pending/reserved notification (e.g. a card authorization
            // hold) could still be reversed or adjusted before it settles — only
            // a confirmed, completed payment is reliable enough to correct the
            // invoice's amount against.
            $amountApplied = $invoice->is_foreign_currency
                && ! in_array($invoice->status, Document::POSTED_STATUSES, true)
                && $paidCurrency === $baseCurrency
                && (float) $invoice->total > 0
                && ($paymentNotification->metadata['confirmed'] ?? false) === true;

            if ($amountApplied) {
                $this->applyCorrectedAmount($invoice, $paymentNotification);
            }

            $invoice->update([
                'metadata' => array_merge($invoice->metadata ?? [], [
                    'payment_notification' => array_filter([
                        'payee_name' => $paymentNotification->metadata['payee_name'] ?? null,
                        'method' => $paymentNotification->metadata['method'] ?? null,
                        'reference_text' => $paymentNotification->metadata['reference_text'] ?? null,
                        'match_confidence' => $confidence,
                        'match_reason' => $reason,
                        'amount_applied' => $amountApplied,
                    ], fn ($v) => $v !== null),
                ]),
            ]);

            $invoice->activities()->create([
                'activity_type' => 'payment_notification_matched',
                'description' => $amountApplied
                    ? sprintf('Matched payment notification (%.0f%% confidence): %s. Amount corrected to the confirmed local total.', $confidence * 100, $reason)
                    : sprintf('Matched payment notification (%.0f%% confidence): %s. No financial changes applied.', $confidence * 100, $reason),
                'metadata' => ['confidence' => $confidence, 'reason' => $reason, 'amount_applied' => $amountApplied],
            ]);

            $paymentNotification->delete();
        });
    }

    private function applyCorrectedAmount(Document $invoice, Document $paymentNotification): void
    {
        $ratio = (float) $paymentNotification->total / (float) $invoice->total;

        DocumentLine::$recalculatesDocumentTotals = false;

        try {
            foreach ($invoice->lines as $line) {
                $line->unit_price = round((float) $line->unit_price * $ratio, 4);
                $line->taxAmountOverride = round((float) $line->tax_amount * $ratio, 2);
                $line->save();
            }
        } finally {
            DocumentLine::$recalculatesDocumentTotals = true;
        }

        $invoice->exchange_rate = round((float) $invoice->exchange_rate * $ratio, 6);
        $invoice->exchange_rate_date = $paymentNotification->issue_date;
        $invoice->exchange_rate_provisional = false;
        $invoice->recalculateTotals();
    }
}
