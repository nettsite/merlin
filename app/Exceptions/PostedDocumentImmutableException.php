<?php

namespace App\Exceptions;

use RuntimeException;

class PostedDocumentImmutableException extends RuntimeException
{
    public static function forLine(string $documentId): self
    {
        return new self("Cannot modify a line on document {$documentId} — it already has a posted journal entry. Reverse the posting first.");
    }
}
