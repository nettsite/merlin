<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliation_matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('statement_line_id')->unique()->constrained('document_lines')->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained('documents')->restrictOnDelete();
            $table->foreignUuid('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('matched_at');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index('document_id');
        });
    }
};
