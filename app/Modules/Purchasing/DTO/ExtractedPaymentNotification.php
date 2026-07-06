<?php

namespace App\Modules\Purchasing\DTO;

use App\Modules\Core\Settings\CurrencySettings;
use Carbon\Carbon;

class ExtractedPaymentNotification
{
    /** @param string[] $warnings */
    public function __construct(
        public readonly ?Carbon $paymentDate,
        public readonly float $paidAmount,
        public readonly string $paidCurrency,
        public readonly ?string $referenceText,
        public readonly ?string $payeeName,
        public readonly ?string $method,
        public readonly bool $confirmed,
        public readonly float $confidence,
        public readonly array $warnings,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            paymentDate: isset($data['payment_date']) ? Carbon::parse($data['payment_date']) : null,
            paidAmount: (float) ($data['paid_amount'] ?? 0),
            paidCurrency: $data['paid_currency'] ?? app(CurrencySettings::class)->base_currency,
            referenceText: $data['reference_text'] ?? null,
            payeeName: $data['payee_name'] ?? null,
            method: $data['method'] ?? null,
            // Fail closed: an ambiguous/missing signal must not drive a financial correction.
            confirmed: (bool) ($data['confirmed'] ?? false),
            confidence: (float) ($data['confidence'] ?? 0),
            warnings: $data['warnings'] ?? [],
        );
    }
}
