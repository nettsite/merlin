<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * status/amount_paid/balance_due/foreign_amount_paid/foreign_balance_due/
     * credits_applied move here from `documents` — the only columns on a
     * document that legitimately change after it's written. Splitting them
     * out is what makes `documents`/`document_lines` actually append-only:
     * every other column is set once, at creation, and never touched again.
     * One row per document (document_id is the primary key, not a separate
     * id) — this is state belonging to the document, not a subsidiary
     * record of its own.
     */
    public function up(): void
    {
        Schema::create('document_balances', function (Blueprint $table) {
            $table->uuid('document_id')->primary();
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->string('status');
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('balance_due', 15, 2)->default(0);
            $table->decimal('foreign_amount_paid', 15, 2)->nullable();
            $table->decimal('foreign_balance_due', 15, 2)->nullable();
            $table->decimal('credits_applied', 15, 2)->default(0);
            $table->timestamps();

            $table->index('status');
        });

        // Safety net for any environment that isn't empty — this database
        // has no rows at the time this migration was written, but a real
        // deploy must not lose existing balances.
        DB::table('documents')->orderBy('id')->chunkById(500, function ($documents) {
            $now = now();
            DB::table('document_balances')->insert($documents->map(fn ($doc) => [
                'document_id' => $doc->id,
                'status' => $doc->status,
                'amount_paid' => $doc->amount_paid,
                'balance_due' => $doc->balance_due,
                'foreign_amount_paid' => $doc->foreign_amount_paid,
                'foreign_balance_due' => $doc->foreign_balance_due,
                'credits_applied' => $doc->credits_applied,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });

        // SQLite validates dependent views eagerly at DROP COLUMN time
        // (MariaDB only at query time) — the postings view must go first or
        // this fails on the test suite's SQLite connection. Recreated,
        // joined to document_balances instead, by the very next migration.
        DB::statement('DROP VIEW IF EXISTS postings');

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn([
                'status',
                'amount_paid',
                'balance_due',
                'foreign_amount_paid',
                'foreign_balance_due',
                'credits_applied',
            ]);
        });
    }
};
