<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_invoices', function (Blueprint $table): void {
            $table->date('next_period_anchor')->nullable()->after('next_invoice_date');
        });

        // Backfill: existing rows start with anchor = current run date.
        DB::table('recurring_invoices')->update([
            'next_period_anchor' => DB::raw('next_invoice_date'),
        ]);
    }
};
