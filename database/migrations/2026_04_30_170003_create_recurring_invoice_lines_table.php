<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recurring_invoice_id')->index();
            $table->foreign('recurring_invoice_id')->references('id')->on('recurring_invoices')->cascadeOnDelete();
            $table->uuid('account_id')->nullable()->index();
            $table->foreign('account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->unsignedInteger('line_number')->default(1);
            $table->string('description');
            $table->decimal('quantity', 10, 4)->default(1);
            $table->decimal('unit_price', 10, 4)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_invoice_lines');
    }
};
