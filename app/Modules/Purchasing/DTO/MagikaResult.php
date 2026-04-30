<?php

namespace App\Modules\Purchasing\DTO;

class MagikaResult
{
    public function __construct(
        public readonly string $label,
        public readonly float $score,
        public readonly string $description,
        public readonly string $mimeType,
        public readonly string $group,
        public readonly bool $isText,
        public readonly bool $usedFallback,
    ) {}

    /**
     * Parse one element from the Rust CLI's --json array output.
     *
     * @param  array<string, mixed>  $item  One element from the top-level JSON array
     */
    public static function fromRustOutput(array $item): self
    {
        $value = $item['result']['value'];
        $output = $value['output'];

        return new self(
            label: $output['label'],
            score: (float) $value['score'],
            description: $output['description'],
            mimeType: $output['mime_type'],
            group: $output['group'],
            isText: (bool) $output['is_text'],
            usedFallback: false,
        );
    }

    public static function fromFallback(string $mimeType): self
    {
        $known = [
            'application/pdf' => ['pdf', 'PDF document (fallback)', 'document', false],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx', 'DOCX document (fallback)', 'document', false],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx', 'XLSX spreadsheet (fallback)', 'spreadsheet', false],
            'text/csv' => ['csv', 'CSV file (fallback)', 'text', true],
        ];

        if (isset($known[$mimeType])) {
            [$label, $description, $group, $isText] = $known[$mimeType];

            return new self(
                label: $label,
                score: 1.0,
                description: $description,
                mimeType: $mimeType,
                group: $group,
                isText: $isText,
                usedFallback: true,
            );
        }

        return new self(
            label: 'unknown',
            score: 0.0,
            description: 'Unknown file type (fallback)',
            mimeType: $mimeType,
            group: 'unknown',
            isText: false,
            usedFallback: true,
        );
    }

    public function isPdf(): bool
    {
        return $this->mimeType === 'application/pdf';
    }

    public function isDocx(): bool
    {
        return $this->mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }

    public function isXlsx(): bool
    {
        return $this->mimeType === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function isCsv(): bool
    {
        return in_array($this->mimeType, ['text/csv', 'text/plain'], true) && $this->label === 'csv';
    }

    public function isSupportedFormat(): bool
    {
        return $this->isPdf() || $this->isDocx() || $this->isXlsx() || $this->isCsv();
    }
}
