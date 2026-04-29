<?php

namespace App\Modules\Purchasing\Services\Pdf;

use App\Exceptions\PdfExtractionException;
use App\Modules\Purchasing\Services\LlmService;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Process\Process;

class PdfExtractor
{
    /** Minimum character count to consider pdftotext output usable. */
    private const MIN_TEXT_LENGTH = 50;

    public function __construct(private readonly LlmService $llm) {}

    /**
     * Extract readable text from a PDF file.
     *
     * Uses pdftotext for text-based PDFs. Falls back to Claude's document API
     * for scanned/image-only PDFs where pdftotext yields little or no text.
     */
    public function extract(string $absolutePath, ?Model $loggable = null): string
    {
        $text = $this->extractWithPdftotext($absolutePath);

        if (strlen(trim($text)) < self::MIN_TEXT_LENGTH) {
            return $this->llm->extractRawTextFromPdf($absolutePath, $loggable);
        }

        return $text;
    }

    private function extractWithPdftotext(string $absolutePath): string
    {
        $process = new Process(['pdftotext', '-layout', $absolutePath, '-']);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new PdfExtractionException(
                "pdftotext failed: {$process->getErrorOutput()}"
            );
        }

        return $process->getOutput();
    }
}
