<?php

namespace App\Modules\Core\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentLine;
use Illuminate\Support\Collection;

class BankStatementProcessingService
{
    public function __construct(
        private readonly DocumentTextExtractor $extractor,
        private readonly LlmService $llm,
    ) {}

    /**
     * Extract transactions from a bank/credit-card statement PDF and populate
     * the document with one DocumentLine per transaction.
     *
     * Callers must delete existing lines before calling this — it only appends.
     */
    public function process(Document $document): void
    {
        $media = $document->getFirstMedia('source_document');

        if (! $media) {
            throw new \RuntimeException("No source document attached to document {$document->id}");
        }

        $text = $this->extractor->extract($media->getPath(), $document);

        $layoutHints = $document->bankTemplate?->layout_hints;

        $extracted = $this->llm->extractBankStatement($text, $layoutHints, $document);

        $reference = $this->buildReference($extracted->bankName, $extracted->periodFrom, $extracted->periodTo);

        $document->update([
            'document_number' => $document->document_number ?? $extracted->statementNumber,
            'reference' => $document->reference ?? $reference,
            'issue_date' => $document->issue_date ?? $extracted->periodTo,
            'currency' => $extracted->currency,
            'llm_confidence' => $extracted->confidence,
            'source' => 'llm_extracted',
            'metadata' => array_merge($document->metadata ?? [], array_filter([
                'bank_name' => $extracted->bankName,
                'account_name' => $extracted->accountName,
                'account_number_last4' => $extracted->accountNumberLast4,
                'statement_number' => $extracted->statementNumber,
                'period_from' => $extracted->periodFrom,
                'period_to' => $extracted->periodTo,
                'opening_balance' => $extracted->openingBalance,
                'closing_balance' => $extracted->closingBalance,
                'balance_reconciled' => $extracted->isBalanceReconciled(),
            ])),
        ]);

        // Pre-index outstanding invoices by document_number for fast lookup.
        /** @var Collection<string, Document> $invoiceIndex */
        $invoiceIndex = Document::salesInvoices()
            ->whereIn('status', ['sent', 'partially_paid'])
            ->whereNotNull('document_number')
            ->pluck('id', 'document_number');

        DocumentLine::$recalculatesDocumentTotals = false;

        try {
            foreach ($extracted->transactions as $i => $transaction) {
                $accountId = $transaction->suggestedAccountCode
                    ? Account::where('code', $transaction->suggestedAccountCode)->value('id')
                    : null;

                $linkedDocumentId = null;

                if ($transaction->suggestedInvoiceNumber !== null) {
                    $linkedDocumentId = $invoiceIndex->get($transaction->suggestedInvoiceNumber);
                }

                $document->lines()->create([
                    'line_number' => $i + 1,
                    'type' => 'service',
                    'description' => $transaction->description,
                    'quantity' => 1,
                    'unit_price' => $transaction->signedAmount(),
                    'line_total' => $transaction->signedAmount(),
                    'tax_rate' => null,
                    'tax_amount' => 0,
                    'account_id' => $accountId,
                    'linked_document_id' => $linkedDocumentId,
                    'llm_account_suggestion' => $accountId,
                    'llm_confidence' => $transaction->accountConfidence,
                    'metadata' => array_filter([
                        'transaction_date' => $transaction->transactionDate,
                        'running_balance' => $transaction->runningBalance,
                        'account_reason' => $transaction->accountReason,
                        'invoice_match_confidence' => $transaction->invoiceMatchConfidence,
                        'invoice_match_reason' => $transaction->invoiceMatchReason,
                        'suggested_invoice_number' => $transaction->suggestedInvoiceNumber,
                    ]),
                ]);
            }
        } finally {
            DocumentLine::$recalculatesDocumentTotals = true;
        }

        $document->recalculateTotals();

        $document->activities()->create([
            'activity_type' => 'llm_extracted',
            'description' => sprintf(
                'Statement extracted by LLM with %.0f%% confidence. %d transaction(s) found.',
                $extracted->confidence * 100,
                count($extracted->transactions),
            ),
            'metadata' => [
                'confidence' => $extracted->confidence,
                'transaction_count' => count($extracted->transactions),
                'balance_reconciled' => $extracted->isBalanceReconciled(),
                'warnings' => $extracted->warnings,
            ],
        ]);
    }

    private function buildReference(?string $bankName, ?string $from, ?string $to): string
    {
        $parts = array_filter([$bankName, $from && $to ? "{$from} to {$to}" : ($from ?? $to)]);

        return $parts ? implode(' — ', $parts) : 'Bank Statement';
    }
}
