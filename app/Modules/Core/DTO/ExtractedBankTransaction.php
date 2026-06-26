<?php

namespace App\Modules\Core\DTO;

readonly class ExtractedBankTransaction
{
    public function __construct(
        public string $transactionDate,
        public string $description,
        public ?float $debit,
        public ?float $credit,
        public ?float $runningBalance,
        public ?string $suggestedAccountCode,
        public ?float $accountConfidence,
        public ?string $accountReason,
        public ?string $suggestedInvoiceNumber,
        public ?float $invoiceMatchConfidence,
        public ?string $invoiceMatchReason,
    ) {}

    public function signedAmount(): float
    {
        if ($this->credit !== null) {
            return $this->credit;
        }

        return -(($this->debit ?? 0));
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            transactionDate: (string) ($data['transaction_date'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            debit: isset($data['debit']) ? (float) $data['debit'] : null,
            credit: isset($data['credit']) ? (float) $data['credit'] : null,
            runningBalance: isset($data['running_balance']) ? (float) $data['running_balance'] : null,
            suggestedAccountCode: $data['suggested_account_code'] ?? null,
            accountConfidence: isset($data['account_confidence']) ? (float) $data['account_confidence'] : null,
            accountReason: $data['account_reason'] ?? null,
            suggestedInvoiceNumber: $data['suggested_invoice_number'] ?? null,
            invoiceMatchConfidence: isset($data['invoice_match_confidence']) ? (float) $data['invoice_match_confidence'] : null,
            invoiceMatchReason: $data['invoice_match_reason'] ?? null,
        );
    }
}
