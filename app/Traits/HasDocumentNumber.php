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

        if (config("documents.types.{$type}.number_format") === 'sequential') {
            return static::nextSequentialNumber($prefix, $type);
        }

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

    /**
     * Prefix + plain integer (R6537), or plain digits alone when the type's
     * prefix is empty. Max is computed numerically in PHP: a string max()
     * ranks R999 above R6536. Scoped by document_type as well as the prefix
     * LIKE pattern, so an empty prefix doesn't force a scan of every row in
     * the table across every document type.
     */
    private static function nextSequentialNumber(string $prefix, string $type): string
    {
        $last = static::withTrashed()
            ->where('document_type', $type)
            ->where('document_number', 'like', "{$prefix}%")
            ->pluck('document_number')
            ->map(fn (string $number): ?int => preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', $number, $matches) === 1
                ? (int) $matches[1]
                : null)
            ->filter()
            ->max();

        return $prefix.(($last ?? 0) + 1);
    }
}
