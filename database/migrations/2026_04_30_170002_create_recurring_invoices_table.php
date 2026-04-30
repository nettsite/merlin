<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id')->index();
            $table->foreign('client_id')->references('id')->on('parties')->cascadeOnDelete();
            $table->uuid('payment_term_id')->nullable()->index();
            $table->foreign('payment_term_id')->references('id')->on('payment_terms')->nullOnDelete();
            $table->uuid('receivable_account_id')->nullable()->index();
            $table->foreign('receivable_account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->json('contact_ids')->nullable();
            $table->string('frequency');
            $table->unsignedInteger('billing_period_day')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('next_invoice_date');
            $table->string('status')->default('active');
            $table->string('currency', 3)->default('ZAR');
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->text('footer')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_invoices');
    }
};
