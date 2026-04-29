<?php

namespace App\Modules\Purchasing\DTO;

class ExtractedInvoiceLine
{
    public function __construct(
        public readonly string $description,
        public readonly float $quantity,
        public readonly float $unitPrice,
        public readonly float $lineTotal,
        public readonly ?float $taxRate,
        public readonly ?string $suggestedAccountCode,
        public readonly float $accountConfidence,
        public readonly string $accountReason,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $rawRate = $data['tax_rate'] ?? null;

        return new self(
            description: $data['description'] ?? '',
            quantity: (float) ($data['quantity'] ?? 1),
            unitPrice: (float) ($data['unit_price'] ?? 0),
            lineTotal: (float) ($data['line_total'] ?? 0),
            taxRate: $rawRate !== null ? (float) $rawRate : null,
            suggestedAccountCode: $data['suggested_account_code'] ?? null,
            accountConfidence: (float) ($data['account_confidence'] ?? 0),
            accountReason: $data['account_reason'] ?? '',
        );
    }
}
