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
        Schema::table('document_lines', function (Blueprint $table): void {
            $table->decimal('foreign_unit_price', 15, 4)->nullable()->after('unit_price');
            $table->decimal('foreign_line_total', 15, 2)->nullable()->after('line_total');
            $table->decimal('foreign_tax_amount', 15, 2)->nullable()->after('tax_amount');
        });
    }
};
