<?php

namespace App\Exceptions;

use RuntimeException;

class PostedDocumentImmutableException extends RuntimeException
{
    public static function forLine(string $documentId): self
    {
        return new self("Cannot modify a line on document {$documentId} — it has already been issued. A credit note is the only way to reverse it.");
    }

    public static function forDocument(string $documentId, string $column): self
    {
        return new self("Cannot modify {$column} on document {$documentId} — it has already been issued. A credit note is the only way to reverse it.");
    }
}
