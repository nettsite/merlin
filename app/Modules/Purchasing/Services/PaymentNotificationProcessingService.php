<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Core\Models\Document;
use App\Modules\Core\Services\LlmService;
use App\Modules\Purchasing\Settings\PurchasingSettings;

class PaymentNotificationProcessingService
{
    public function __construct(
        private readonly LlmService $llm,
        private readonly PaymentNotificationMatcher $matcher,
        private readonly PurchasingSettings $purchasingSettings,
    ) {}

    /**
     * Extract a payment notification's header fields onto the (already
     * classified) Document, then attempt to match and merge it into the
     * purchase invoice it settles.
     *
     * Text is passed in already extracted by the caller (InvoiceProcessingService
     * classifies after extracting) — no duplicate extraction here.
     */
    public function process(Document $document, string $text): void
    {
        $extracted = $this->llm->extractPaymentNotification($text, $document);

        $document->update([
            'reference' => $document->reference ?? $extracted->referenceText,
            'issue_date' => $extracted->paymentDate,
            'currency' => strtoupper($extracted->paidCurrency),
            'subtotal' => $extracted->paidAmount,
            'tax_total' => 0,
            'total' => $extracted->paidAmount,
            'llm_confidence' => $extracted->confidence,
            'source' => 'llm_extracted',
            'metadata' => array_filter([
                'reference_text' => $extracted->referenceText,
                'payee_name' => $extracted->payeeName,
                'method' => $extracted->method,
                'confirmed' => $extracted->confirmed,
            ], fn ($v) => $v !== null),
        ]);

        $document->activities()->create([
            'activity_type' => 'llm_extracted',
            'description' => sprintf(
                'Payment notification extracted by LLM with %.0f%% confidence.',
                $extracted->confidence * 100,
            ),
            'metadata' => [
                'confidence' => $extracted->confidence,
                'warnings' => $extracted->warnings,
            ],
        ]);

        $this->attemptMatch($document);
    }

    private function attemptMatch(Document $document): void
    {
        $match = $this->matcher->findInvoiceMatch($document);

        if ($match === null) {
            return;
        }

        if ($match['confidence'] >= $this->purchasingSettings->payment_match_auto_confidence) {
            $this->matcher->merge($match['document'], $document, $match['confidence'], $match['reason']);

            return;
        }

        $document->update([
            'metadata' => array_merge($document->metadata ?? [], [
                'suggested_invoice_id' => $match['document']->id,
                'match_confidence' => $match['confidence'],
                'match_reason' => $match['reason'],
            ]),
        ]);
    }
}
