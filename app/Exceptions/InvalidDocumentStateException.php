<?php

namespace App\Exceptions;

use App\Modules\Core\Models\Document;
use RuntimeException;

class InvalidDocumentStateException extends RuntimeException
{
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
