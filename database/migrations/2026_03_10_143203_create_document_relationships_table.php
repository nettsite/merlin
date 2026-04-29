<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_relationships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('parent_document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('child_document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('relationship_type'); // 'converted_from' | 'credit_for' | 'debit_for' | 'duplicate_of'
            $table->timestamps();

            $table->unique(['parent_document_id', 'child_document_id', 'relationship_type'], 'doc_relationships_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_relationships');
    }
};
