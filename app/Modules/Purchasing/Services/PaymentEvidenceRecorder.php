<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Services\DocumentService;
use Carbon\CarbonInterface;

/**
 * Single gate for turning confirmed payment evidence — a matched payment
 * notification, or a receipt recognised as a paid reissue — into a real GL
 * payment against a purchase invoice.
 *
 * Multiple independent confirmations often arrive for the same payment (a
 * bank advice, a payment-gateway email, and the supplier's own "paid"
 * receipt), so every caller routes through here instead of calling
 * DocumentService::recordPurchasePayment() directly, to avoid recording the
 * same payment more than once.
 */
class PaymentEvidenceRecorder
{
    private const AMOUNT_TOLERANCE = 0.01;

    public function __construct(
        private readonly DocumentService $documentService,
        private readonly BillingSettings $billingSettings,
    ) {}

    public function record(Document $invoice, float $amount, CarbonInterface $date, ?string $reference, string $evidenceSource): void
    {
        if ($amount <= 0) {
            return;
        }

        $balanceDue = (float) $invoice->balance_due;

        // balance_due is the source of truth for what's already settled —
        // recordPayment() decrements it on every recorded payment, so a
        // second confirmation for an already-settled invoice lands here.
        if ($balanceDue <= self::AMOUNT_TOLERANCE) {
            $invoice->activities()->create([
                'activity_type' => 'payment_evidence_noted',
                'description' => "Additional payment confirmation received ({$evidenceSource}) — invoice already settled, no new GL entry created.",
            ]);

            return;
        }

        $applyAmount = min($amount, $balanceDue);

        if (! in_array($invoice->status, ['posted', 'partially_paid'], true)) {
            // No GL row exists to post a payment against yet — stash the
            // evidence and replay it once the invoice is posted (see
            // DocumentService::recordPendingPurchasePayment()).
            $invoice->update([
                'metadata' => array_merge($invoice->metadata ?? [], [
                    'pending_payment' => [
                        'amount' => $applyAmount,
                        'date' => $date->toDateString(),
                        'reference' => $reference,
                        'evidence_source' => $evidenceSource,
                    ],
                ]),
            ]);

            $invoice->activities()->create([
                'activity_type' => 'payment_evidence_pending',
                'description' => "Payment confirmation received ({$evidenceSource}) but invoice not yet posted — will record once posted.",
            ]);

            return;
        }

        $this->documentService->recordPurchasePayment($invoice, [
            'amount' => $applyAmount,
            'date' => $date->toDateString(),
            'reference' => $reference,
            'contra_account_id' => $this->billingSettings->default_contra_account_id,
        ], null);
    }
}
