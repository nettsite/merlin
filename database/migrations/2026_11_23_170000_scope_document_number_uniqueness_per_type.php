<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sales invoices and payments both now number new documents as plain
     * digits (no prefix), so the same number can legitimately belong to one
     * of each (e.g. invoice 6558 and payment 6558) — document_number alone
     * can no longer be globally unique. Every other type still has a
     * distinct prefix, so this only relaxes what was already redundant for
     * them.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropUnique('documents_document_number_unique');
            $table->unique(['document_type', 'document_number']);
        });
    }
};
