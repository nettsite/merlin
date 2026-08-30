<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The journal layer (create_journal_tables, 2026-08-29) is superseded by
     * the postings view: every posting it exists to replicate is derivable
     * from documents/document_lines directly, and the arithmetic that
     * UnbalancedJournalEntryException used to guard is now a structural
     * invariant of Document::recalculateTotals() rather than something
     * asserted at write time. journal_lines must drop first — it has the
     * foreign key onto journal_entries.
     */
    public function up(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
    }
};
