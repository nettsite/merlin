<?php

namespace App\Exceptions;

use RuntimeException;

class UnbalancedJournalEntryException extends RuntimeException
{
    public static function forTotals(string $source, float $debit, float $credit): self
    {
        return new self(sprintf(
            'Journal entry "%s" is unbalanced: debits %.2f, credits %.2f.',
            $source,
            $debit,
            $credit,
        ));
    }
}
