<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Plain-text (not JSON) agent — extracts raw text from a scanned PDF via vision.
 */
#[MaxTokens(4096)]
class PdfVisionExtractionAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Extract all text from the attached PDF document. Return only the extracted text, '.
            'preserving the layout as best as possible. Include all numbers, dates, company names, '.
            'line items, and totals. Do not summarize or interpret — just extract the text exactly as it appears.';
    }
}
