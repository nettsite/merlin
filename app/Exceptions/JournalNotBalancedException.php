<?php

namespace App\Exceptions;

use RuntimeException;

class JournalNotBalancedException extends RuntimeException
{
    public static function forDocument(string $documentNumber, float $difference): self
    {
        return new self(sprintf(
            'Journal %s does not balance — debits and credits differ by %.2f.',
            $documentNumber,
            $difference,
        ));
    }

    public static function controlAccountUsed(string $documentNumber, string $accountName): self
    {
        return new self(sprintf(
            'Journal %s cannot post to "%s" — it is a control account (AR/AP). Use an invoice, credit note, or payment instead.',
            $documentNumber,
            $accountName,
        ));
    }
}
