<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_type');
            $table->string('direction'); // 'inbound' | 'outbound'
            $table->string('document_number')->unique();
            $table->string('reference')->nullable();
            $table->foreignUuid('party_id')->constrained('parties');
            $table->uuid('contact_id')->nullable();
            $table->uuid('billing_address_id')->nullable();
            $table->string('status');
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('currency', 3)->default('ZAR');
            $table->decimal('exchange_rate', 10, 6)->default(1.000000);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('balance_due', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->text('footer')->nullable();
            $table->uuid('payable_account_id')->nullable();
            $table->string('source')->default('manual'); // 'manual' | 'llm_extracted' | 'imported'
            $table->decimal('llm_confidence', 5, 4)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('contact_id')->references('id')->on('persons')->nullOnDelete();
            $table->foreign('billing_address_id')->references('id')->on('addresses')->nullOnDelete();
            $table->foreign('payable_account_id')->references('id')->on('accounts')->nullOnDelete();

            $table->index('document_type');
            $table->index('status');
            $table->index('issue_date');
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
