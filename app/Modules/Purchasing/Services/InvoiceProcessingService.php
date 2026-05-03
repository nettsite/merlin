<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Core\Models\Party;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Settings\PurchasingSettings;
use Illuminate\Support\Facades\Log;

class InvoiceProcessingService
{
    public function __construct(
        private readonly DocumentTextExtractor $extractor,
        private readonly LlmService $llm,
        private readonly SupplierResolver $supplierResolver,
        private readonly AccountResolver $accountResolver,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly PostingRuleService $postingRuleService,
        private readonly CurrencySettings $currencySettings,
        private readonly PurchasingSettings $purchasingSettings,
    ) {}

    /**
     * Process an uploaded invoice PDF attached to a Document.
     *
     * Extracts text, calls the LLM, resolves supplier and GL accounts,
     * and populates the document with lines ready for human review.
     */
    public function process(Document $document): void
    {
        $media = $document->getFirstMedia('source_document');

        if (! $media) {
            throw new \RuntimeException("No source document attached to document {$document->id}");
        }

        // 1. Extract text from PDF (pdftotext → Claude vision fallback)
        $text = $this->extractor->extract($media->getPath(), $document);

        // 2. Build supplier history context if we already know the supplier
        $history = $document->party_id ? $this->getSupplierHistory($document->party) : [];

        // 3. Call LLM for structured extraction
        $extracted = $this->llm->extractInvoice($text, $history, $document);

        // 4. Resolve supplier (no-op if party_id already set)
        $this->supplierResolver->resolve($document, $extracted);
        $document->refresh();

        // 5. Resolve currency and exchange rate
        $currency = strtoupper($extracted->currency ?: $this->currencySettings->base_currency);
        $base = strtoupper($this->currencySettings->base_currency);
        $isForeign = $currency !== $base;

        $exchangeRate = 1.0;
        $exchangeRateDate = null;

        if ($isForeign) {
            $exchangeRate = $this->exchangeRateService->getRate($currency);
            $exchangeRateDate = now()->toDateString();
        }

        // 6. Update document header fields
        $document->update([
            'reference' => $document->reference ?? $extracted->invoiceNumber,
            'issue_date' => $document->issue_date ?? $extracted->issueDate,
            'due_date' => $document->due_date ?? $extracted->dueDate,
            'currency' => $currency,
            'exchange_rate' => $exchangeRate,
            'exchange_rate_date' => $exchangeRateDate,
            'exchange_rate_provisional' => $isForeign,
            'llm_confidence' => $extracted->confidence,
            'source' => 'llm_extracted',
        ]);

        // 7. Create document lines with account resolution
        //
        // Tax rate precedence:
        //   1. LLM-provided per-line tax_rate (explicit, most accurate)
        //   2. If the header tax_total > 0 but no per-line rate, default to the purchasing settings rate
        //   3. If the header tax_total is zero, no tax on any line
        $headerHasTax = $extracted->taxTotal > 0;

        // Detect VAT-inclusive line prices: if sum(extracted line totals) ≈ extracted.total
        // and the invoice has tax, the LLM extracted amounts that already include VAT.
        // In that case we must back-calculate to ex-VAT before storing.
        $extractedLineSum = array_sum(array_map(fn ($l) => $l->lineTotal, $extracted->lines));
        $vatInclusivePrices = $extracted->total > 0
            && $headerHasTax
            && abs($extractedLineSum - $extracted->total) / $extracted->total < 0.02;

        foreach ($extracted->lines as $i => $extractedLine) {
            $accountData = $this->accountResolver->resolve(
                $extractedLine->description,
                $document->party_id,
                $extractedLine,
            );

            $taxRate = match (true) {
                $extractedLine->taxRate !== null => $extractedLine->taxRate,
                $headerHasTax => $this->purchasingSettings->tax_default_rate,
                default => null,
            };

            // When prices are VAT-inclusive, back-calculate to the ex-VAT amount so
            // DocumentLine::calculateTotals() doesn't add tax on top of a price that
            // already includes it.
            $divisor = ($vatInclusivePrices && $taxRate !== null && $taxRate > 0)
                ? (1 + $taxRate / 100)
                : 1;

            $exVatUnitPrice = round($extractedLine->unitPrice / $divisor, 4);
            $exVatForeignLineTotal = $extractedLine->lineTotal > 0
                ? round($extractedLine->lineTotal / $divisor, 2)
                : round($extractedLine->lineTotal / $divisor, 2); // preserve negative discounts

            $line = $document->lines()->make(array_merge([
                'line_number' => $i + 1,
                'type' => 'service',
                'description' => $extractedLine->description,
                'quantity' => $extractedLine->quantity,
                'unit_price' => $isForeign
                    ? round($exVatUnitPrice * $exchangeRate, 4)
                    : $exVatUnitPrice,
                'foreign_unit_price' => $isForeign ? $exVatUnitPrice : null,
                'foreign_line_total' => $isForeign ? $exVatForeignLineTotal : null,
                'tax_rate' => $taxRate,
            ], $accountData));

            if ($isForeign && $line->foreign_line_total !== null) {
                $line->foreign_tax_amount = $line->tax_rate !== null
                    ? round((float) $line->foreign_line_total * ((float) $line->tax_rate / 100), 2)
                    : 0;
            }

            $line->save();
        }

        // 8. Record LLM extraction activity
        $document->activities()->create([
            'activity_type' => 'llm_extracted',
            'description' => sprintf(
                'Invoice extracted by LLM with %.0f%% confidence. %d line(s) created.',
                $extracted->confidence * 100,
                count($extracted->lines),
            ),
            'metadata' => [
                'confidence' => $extracted->confidence,
                'warnings' => $extracted->warnings,
                'supplier_resolved' => $document->party_id !== null,
            ],
        ]);

        if (! empty($extracted->warnings)) {
            Log::debug('InvoiceProcessingService: LLM warnings', [
                'document_id' => $document->id,
                'warnings' => $extracted->warnings,
            ]);
        }

        // 9. Evaluate autonomous posting rules
        $this->postingRuleService->evaluateAndPost($document);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getSupplierHistory(Party $supplier): array
    {
        return Document::query()
            ->where('party_id', $supplier->id)
            ->where('document_type', 'purchase_invoice')
            ->where('status', 'posted')
            ->orderByDesc('issue_date')
            ->limit(5)
            ->with('lines.account')
            ->get()
            ->map(fn (Document $doc) => [
                'invoice_number' => $doc->reference,
                'total' => $doc->total,
                'lines' => $doc->lines->map(fn ($l) => [
                    'description' => $l->description,
                    'account_code' => $l->account?->code,
                    'account_name' => $l->account?->name,
                ])->toArray(),
            ])
            ->toArray();
    }
}
