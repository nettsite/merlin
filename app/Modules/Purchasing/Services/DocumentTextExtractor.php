<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Purchasing\Services\Pdf\MagikaService;
use App\Modules\Purchasing\Services\Pdf\PdfExtractor;
use Illuminate\Database\Eloquent\Model;
use Paperdoc\Facades\Paperdoc;

class DocumentTextExtractor
{
    public function __construct(
        private readonly PdfExtractor $pdfExtractor,
        private readonly MagikaService $magika,
    ) {}

    /**
     * Extract readable text from any supported invoice file.
     *
     * PDFs go through pdftotext + Claude vision fallback.
     * DOCX, XLSX, and CSV are rendered to Markdown via Paperdoc.
     */
    public function extract(string $absolutePath, ?Model $loggable = null): string
    {
        $result = $this->magika->detect($absolutePath);

        if ($result->isPdf()) {
            return $this->pdfExtractor->extract($absolutePath, $loggable);
        }

        $doc = Paperdoc::open($absolutePath);

        return Paperdoc::renderAs($doc, 'md');
    }
}
