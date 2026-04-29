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
        if ($mimeType === 'application/pdf') {
            return new self(
                label: 'pdf',
                score: 1.0,
                description: 'PDF document (fallback)',
                mimeType: $mimeType,
                group: 'document',
                isText: false,
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
        return $this->label === 'pdf';
    }
}
