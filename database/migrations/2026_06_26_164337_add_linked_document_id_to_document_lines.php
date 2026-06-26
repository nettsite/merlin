<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('document_lines', function (Blueprint $table) {
            $table->uuid('linked_document_id')->nullable()->index()->after('document_id');
            $table->foreign('linked_document_id')->references('id')->on('documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_lines', function (Blueprint $table) {
            $table->dropForeign(['linked_document_id']);
            $table->dropColumn('linked_document_id');
        });
    }
};
