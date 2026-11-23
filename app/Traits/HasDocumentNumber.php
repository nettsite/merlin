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
            } while ($attempts < 5 && static::withTrashed()
                ->where('document_type', $document->document_type)
                ->where('document_number', $number)
                ->exists());

            static::advanceConfiguredNextNumber($document->document_type, $number);
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
     * ranks R999 above R6536. Scoped by document_type, so an empty prefix
     * doesn't force a scan of every row in the table across every document
     * type.
     *
     * A type can optionally name a previous prefix it used to use (config
     * `legacy_prefix`) — e.g. when dropping a prefix going forward but
     * continuing the same count rather than restarting at 1. Existing rows
     * under either prefix count towards the running maximum; new numbers
     * always use the current prefix.
     *
     * A type can also optionally name a settings class + property (config
     * `next_number_setting`) holding a user-configurable floor — e.g. so a
     * business can jump numbering forward. The higher of the two wins, so
     * the configured floor can never produce a number already used.
     */
    private static function nextSequentialNumber(string $prefix, string $type): string
    {
        $legacyPrefix = config("documents.types.{$type}.legacy_prefix");
        $prefixes = array_unique(array_filter([$prefix, $legacyPrefix], fn (?string $p): bool => $p !== null));

        $last = static::withTrashed()
            ->where('document_type', $type)
            ->where(function ($query) use ($prefixes): void {
                foreach ($prefixes as $p) {
                    $query->orWhere('document_number', 'like', "{$p}%");
                }
            })
            ->pluck('document_number')
            ->map(function (string $number) use ($prefixes): ?int {
                foreach ($prefixes as $p) {
                    if (preg_match('/^'.preg_quote($p, '/').'(\d+)$/', $number, $matches) === 1) {
                        return (int) $matches[1];
                    }
                }

                return null;
            })
            ->filter()
            ->max();

        $natural = ($last ?? 0) + 1;
        $configured = static::configuredNextNumber($type);

        return $prefix.max($natural, $configured ?? 0);
    }

    private static function configuredNextNumber(string $type): ?int
    {
        $setting = config("documents.types.{$type}.next_number_setting");

        if ($setting === null) {
            return null;
        }

        [$settingsClass, $property] = $setting;
        $value = app($settingsClass)->{$property};

        return $value !== null ? (int) $value : null;
    }

    /**
     * After a number is actually assigned, advance the configured setting
     * (if any) past it, so it always reflects the true next number without
     * the user having to touch it again.
     */
    private static function advanceConfiguredNextNumber(string $type, string $number): void
    {
        $setting = config("documents.types.{$type}.next_number_setting");

        if ($setting === null) {
            return;
        }

        [$settingsClass, $property] = $setting;
        $prefix = config("documents.types.{$type}.prefix", '');
        $numericPart = (int) substr($number, strlen($prefix));

        $settings = app($settingsClass);
        $settings->{$property} = $numericPart + 1;
        $settings->save();
    }
}
