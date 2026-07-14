<?php

namespace App\Modules\Purchasing\Services;

class DocumentKindClassifier
{
    public const KIND_INVOICE = 'invoice';

    public const KIND_PAYMENT_NOTIFICATION = 'payment_notification';

    /**
     * Payment notifications (PayPal receipts, FNB Connect emails, EFT
     * confirmations) use very distinct boilerplate that invoices never do.
     * Invoices, in turn, use billing language these notifications never do.
     * We classify by which vocabulary dominates rather than requiring an
     * extra LLM call for what is normally an obvious distinction.
     */
    private const PAYMENT_SIGNALS = [
        '/\bpaypal\b/i',
        '/\bpayfast\b/i',
        '/\bproof of payment\b/i',
        '/\byou(?:\'ve| have)? sent a payment\b/i',
        '/\byou successfully paid\b/i',
        '/\bpayment (?:confirmation|notification|receipt)\b/i',
        '/\beft confirmation\b/i',
        '/\bfnb connect\b/i',
        '/\breceipt for your payment\b/i',
        '/\bpayment was successful\b/i',
        '/\breserved for purchase\b/i',
        '/\bsent from an unattended mailbox\b/i',
    ];

    private const INVOICE_SIGNALS = [
        '/\btax invoice\b/i',
        '/\binvoice\s*(?:no|number|#)\b/i',
        '/\bsubtotal\b/i',
        '/\bvat\s*(?:no|number|reg)\b/i',
        '/\bpurchase order\b/i',
        '/\bbill\s*to\b/i',
        '/\bdue date\b/i',
        '/\bquantity\b/i',
    ];

    /**
     * A supplier "tax invoice / receipt" combo document scores as
     * KIND_INVOICE (it carries invoice vocabulary) but still marks the
     * purchase as paid. Checked separately from classify() so the invoice
     * vs payment_notification split is untouched — this only flags whether
     * an invoice-classified document also carries paid-evidence language.
     */
    private const PAID_SIGNALS = [
        '/\bpaid in full\b/i',
        '/\bbalance due:?\s*(?:r|R|\$)?\s*0(?:\.00)?\b/i',
        '/\bthis invoice has been paid\b/i',
        '/\bpayment received\b/i',
        '/\bamount paid\b/i',
        '/\breceipt\b/i',
    ];

    public function classify(string $text): string
    {
        $paymentScore = $this->countMatches(self::PAYMENT_SIGNALS, $text);
        $invoiceScore = $this->countMatches(self::INVOICE_SIGNALS, $text);

        if ($paymentScore > 0 && $paymentScore >= $invoiceScore) {
            return self::KIND_PAYMENT_NOTIFICATION;
        }

        return self::KIND_INVOICE;
    }

    public function hasPaidSignal(string $text): bool
    {
        return $this->countMatches(self::PAID_SIGNALS, $text) > 0;
    }

    /** @param string[] $patterns */
    private function countMatches(array $patterns, string $text): int
    {
        $count = 0;

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $count++;
            }
        }

        return $count;
    }
}
