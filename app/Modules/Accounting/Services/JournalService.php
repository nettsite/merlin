<?php

namespace App\Modules\Accounting\Services;

use App\Exceptions\UnbalancedJournalEntryException;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\Document;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of double-entry postings. Nothing else may create a
 * JournalEntry or JournalLine — this is what makes the ledger authoritative
 * instead of each report re-deriving debits/credits from document headers.
 */
class JournalService
{
    private const TOLERANCE = 0.01;

    /**
     * Post a balanced journal entry. Idempotent on (document_id, source): a
     * second call for the same document event is a no-op that returns the
     * existing entry, rather than posting a duplicate.
     *
     * @param  array<int, array{account_id: string, debit?: float, credit?: float, party_id?: ?string, description?: ?string}>  $lines
     */
    public function post(
        string $source,
        CarbonInterface $date,
        string $description,
        array $lines,
        ?Document $document = null,
    ): JournalEntry {
        if ($document !== null) {
            $existing = JournalEntry::where('document_id', $document->id)
                ->where('source', $source)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $totalDebit = array_sum(array_map(fn (array $l) => (float) ($l['debit'] ?? 0), $lines));
        $totalCredit = array_sum(array_map(fn (array $l) => (float) ($l['credit'] ?? 0), $lines));

        if (abs($totalDebit - $totalCredit) > self::TOLERANCE) {
            throw UnbalancedJournalEntryException::forTotals($source, $totalDebit, $totalCredit);
        }

        try {
            return DB::transaction(function () use ($source, $date, $description, $lines, $document): JournalEntry {
                $entry = JournalEntry::create([
                    'document_id' => $document?->id,
                    'entry_date' => $date->toDateString(),
                    'source' => $source,
                    'description' => $description,
                ]);

                foreach ($lines as $line) {
                    $entry->lines()->create([
                        'account_id' => $line['account_id'],
                        'party_id' => $line['party_id'] ?? null,
                        'debit' => $line['debit'] ?? 0,
                        'credit' => $line['credit'] ?? 0,
                        'description' => $line['description'] ?? null,
                    ]);
                }

                return $entry;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race against another writer for the same (document_id,
            // source) pair — the entry now exists, so return it rather than
            // duplicating the posting.
            return JournalEntry::where('document_id', $document?->id)
                ->where('source', $source)
                ->firstOrFail();
        }
    }

    /**
     * Reverse a posted entry: writes a new entry with every line's debit and
     * credit swapped, and stamps reversed_by_id on the original. The
     * original is never mutated or deleted — the journal is append-only.
     */
    public function reverse(JournalEntry $entry, string $reason): JournalEntry
    {
        if ($entry->isReversed()) {
            return $entry->reversedBy;
        }

        return DB::transaction(function () use ($entry, $reason): JournalEntry {
            $reversal = JournalEntry::create([
                'document_id' => $entry->document_id,
                'entry_date' => now()->toDateString(),
                'source' => "{$entry->source}_reversed",
                'description' => "Reversal of \"{$entry->description}\": {$reason}",
            ]);

            foreach ($entry->lines as $line) {
                $reversal->lines()->create([
                    'account_id' => $line->account_id,
                    'party_id' => $line->party_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'description' => $line->description,
                ]);
            }

            $entry->update(['reversed_by_id' => $reversal->id]);

            return $reversal;
        });
    }
}
