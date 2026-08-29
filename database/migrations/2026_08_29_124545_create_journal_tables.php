<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('document_id')->nullable();
            $table->date('entry_date');
            $table->string('source');
            $table->string('description');
            $table->uuid('reversed_by_id')->nullable();
            $table->timestamps();

            $table->foreign('document_id')->references('id')->on('documents')->nullOnDelete();
            $table->foreign('reversed_by_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->unique(['document_id', 'source']);
            $table->index(['entry_date', 'source']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->uuid('account_id');
            $table->uuid('party_id')->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts');
            $table->foreign('party_id')->references('id')->on('parties')->nullOnDelete();
            $table->index(['account_id', 'journal_entry_id']);
        });
    }
};
