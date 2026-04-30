<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('media')
            ->where('collection_name', 'source_pdf')
            ->update(['collection_name' => 'source_document']);
    }

    public function down(): void
    {
        DB::table('media')
            ->where('collection_name', 'source_document')
            ->update(['collection_name' => 'source_pdf']);
    }
};
