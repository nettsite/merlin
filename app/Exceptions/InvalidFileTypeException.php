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

    public static function unsupportedFormat(string $path, string $detectedMimeType): self
    {
        return new self(sprintf(
            'File "%s" is not a supported format (detected: %s). Supported: PDF, DOCX, XLSX, CSV.',
            basename($path),
            $detectedMimeType,
        ));
    }
}
