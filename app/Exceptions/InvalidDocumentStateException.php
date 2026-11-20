<?php

namespace App\Exceptions;

use App\Modules\Core\Models\Document;
use RuntimeException;

class InvalidDocumentStateException extends RuntimeException
{
    public static function cannotCancel(Document $doc): self
    {
        return new self(sprintf(
            'Invoice [%s] cannot be cancelled: only a sent invoice with a zero total and no payments or credit notes can be. Use a credit note for anything else.',
            $doc->document_number,
        ));
    }

    public static function transition(Document $doc, string $to): self
    {
        return new self(sprintf(
            'Cannot transition %s [%s] from "%s" to "%s".',
            $doc->document_type,
            $doc->document_number,
            $doc->status,
            $to,
        ));
    }
}
