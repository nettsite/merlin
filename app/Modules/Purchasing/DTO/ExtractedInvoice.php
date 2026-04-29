<?php

namespace App\Modules\Purchasing\DTO;

use App\Modules\Core\Settings\CurrencySettings;
use Carbon\Carbon;

class ExtractedInvoice
{
    /**
     * @param  ExtractedInvoiceLine[]  $lines
     * @param  string[]  $warnings
     */
    public function __construct(
        public readonly ?string $supplierName,
        public readonly ?string $supplierTaxNumber,
        public readonly ?string $supplierEmail,
        public readonly ?string $supplierPhone,
        public readonly ?string $invoiceNumber,
        public readonly ?Carbon $issueDate,
        public readonly ?Carbon $dueDate,
        public readonly string $currency,
        public readonly float $subtotal,
        public readonly float $taxTotal,
        public readonly float $total,
        public readonly array $lines,
        public readonly float $confidence,
        public readonly array $warnings,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $lines = array_map(
            fn (array $line) => ExtractedInvoiceLine::fromArray($line),
            $data['lines'] ?? [],
        );

        return new self(
            supplierName: $data['supplier_name'] ?? null,
            supplierTaxNumber: $data['supplier_tax_number'] ?? null,
            supplierEmail: $data['supplier_email'] ?? null,
            supplierPhone: $data['supplier_phone'] ?? null,
            invoiceNumber: $data['invoice_number'] ?? null,
            issueDate: isset($data['issue_date']) ? Carbon::parse($data['issue_date']) : null,
            dueDate: isset($data['due_date']) ? Carbon::parse($data['due_date']) : null,
            currency: $data['currency'] ?? app(CurrencySettings::class)->base_currency,
            subtotal: (float) ($data['subtotal'] ?? 0),
            taxTotal: (float) ($data['tax_total'] ?? 0),
            total: (float) ($data['total'] ?? 0),
            lines: $lines,
            confidence: (float) ($data['confidence'] ?? 0),
            warnings: $data['warnings'] ?? [],
        );
    }
}
