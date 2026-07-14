<?php

namespace App\Traits;

use App\Modules\Core\Models\Document;

trait HasDocumentNumber
{
    public static function bootHasDocumentNumber(): void
    {
        static::creating(function (Document $document) {
            if (! empty($document->document_number)) {
                return;
            }

            if (config("documents.types.{$document->document_type}.auto_number") === false) {
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

        // Scoped by prefix, not document_type: a document can be reclassified
        // after numbering (e.g. purchase_invoice -> payment_notification when
        // InvoiceProcessingService detects it's actually a payment receipt).
        // Its number stays but its type no longer matches — filtering by type
        // here would make that row invisible to future max() lookups even
        // though document_number is globally unique, letting a later document
        // compute the same "next" number and collide. Each type has its own
        // distinct prefix (see config/documents.php), so the prefix+year LIKE
        // pattern alone is already an exact scope.
        $last = static::withTrashed()
            ->where('document_number', 'like', "{$prefix}-{$year}-%")
            ->max('document_number');

        $next = $last
            ? (int) substr($last, strrpos($last, '-') + 1) + 1
            : 1;

        return sprintf('%s-%d-%05d', $prefix, $year, $next);
    }
}
