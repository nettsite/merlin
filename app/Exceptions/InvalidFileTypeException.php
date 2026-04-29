<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidFileTypeException extends RuntimeException
{
    public static function notPdf(string $path, string $detectedMimeType): self
    {
        return new self(sprintf(
            'File "%s" is not a PDF (detected: %s).',
            basename($path),
            $detectedMimeType,
        ));
    }
}
