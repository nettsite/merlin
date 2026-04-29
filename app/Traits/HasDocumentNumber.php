<?php

namespace App\Traits;

use App\Modules\Purchasing\Models\Document;

trait HasDocumentNumber
{
    public static function bootHasDocumentNumber(): void
    {
        static::creating(function (Document $document) {
            if (! empty($document->document_number)) {
                return;
            }

            $attempts = 0;

            do {
                $number = static::nextDocumentNumber($document->document_type);
                $document->document_number = $number;
                $attempts++;
            } while ($attempts < 5 && static::withTrashed()->where('document_number', $number)->exists());
        });
    }

    public static function nextDocumentNumber(string $type): string
    {
        $prefix = config("documents.types.{$type}.prefix", 'DOC');
        $year = now()->year;

        $last = static::withTrashed()
            ->where('document_type', $type)
            ->where('document_number', 'like', "{$prefix}-{$year}-%")
            ->max('document_number');

        $next = $last
            ? (int) substr($last, strrpos($last, '-') + 1) + 1
            : 1;

        return sprintf('%s-%d-%05d', $prefix, $year, $next);
    }
}
