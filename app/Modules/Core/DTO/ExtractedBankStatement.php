<?php

namespace App\Modules\Core\DTO;

use App\Modules\Core\Settings\CurrencySettings;

readonly class ExtractedBankStatement
{
    /**
     * @param  ExtractedBankTransaction[]  $transactions
     * @param  string[]  $warnings
     */
    public function __construct(
        public ?string $bankName,
        public ?string $accountName,
        public ?string $accountNumberLast4,
        public ?string $statementNumber,
        public ?string $periodFrom,
        public ?string $periodTo,
        public ?float $openingBalance,
        public ?float $closingBalance,
        public string $currency,
        public array $transactions,
        public float $confidence,
        public array $warnings,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $transactions = array_map(
            fn (array $t) => ExtractedBankTransaction::fromArray($t),
            $data['transactions'] ?? [],
        );

        return new self(
            bankName: $data['bank_name'] ?? null,
            accountName: $data['account_name'] ?? null,
            accountNumberLast4: $data['account_number_last4'] ?? null,
            statementNumber: $data['statement_number'] ?? null,
            periodFrom: $data['period_from'] ?? null,
            periodTo: $data['period_to'] ?? null,
            openingBalance: isset($data['opening_balance']) ? (float) $data['opening_balance'] : null,
            closingBalance: isset($data['closing_balance']) ? (float) $data['closing_balance'] : null,
            currency: $data['currency'] ?? app(CurrencySettings::class)->base_currency,
            transactions: $transactions,
            confidence: (float) ($data['confidence'] ?? 0),
            warnings: $data['warnings'] ?? [],
        );
    }

    public function isBalanceReconciled(): bool
    {
        if ($this->openingBalance === null || $this->closingBalance === null || $this->transactions === []) {
            return false;
        }

        $netMovement = array_sum(array_map(fn ($t) => $t->signedAmount(), $this->transactions));
        $expected = $this->closingBalance - $this->openingBalance;

        return abs($netMovement - $expected) <= max(abs($expected) * 0.02, 0.05);
    }
}
