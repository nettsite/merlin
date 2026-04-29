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
        Schema::table('documents', function (Blueprint $table): void {
            $table->date('exchange_rate_date')->nullable()->after('exchange_rate');
            $table->boolean('exchange_rate_provisional')->default(true)->after('exchange_rate_date');

            $table->decimal('foreign_subtotal', 15, 2)->nullable()->after('balance_due');
            $table->decimal('foreign_tax_total', 15, 2)->nullable()->after('foreign_subtotal');
            $table->decimal('foreign_total', 15, 2)->nullable()->after('foreign_tax_total');
            $table->decimal('foreign_amount_paid', 15, 2)->nullable()->after('foreign_total');
            $table->decimal('foreign_balance_due', 15, 2)->nullable()->after('foreign_amount_paid');
        });
    }
};
