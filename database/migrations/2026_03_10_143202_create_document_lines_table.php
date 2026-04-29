<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('type'); // 'item' | 'service' | 'description' | 'discount'
            $table->string('description');
            $table->uuid('account_id')->nullable();
            $table->uuid('product_id')->nullable(); // future
            $table->decimal('quantity', 10, 4)->default(1);
            $table->string('unit')->nullable();
            $table->decimal('unit_price', 15, 4);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);
            $table->decimal('tax_rate', 5, 2)->nullable(); // stored at time of entry; null = exempt
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->uuid('llm_account_suggestion')->nullable();
            $table->decimal('llm_confidence', 5, 4)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('llm_account_suggestion')->references('id')->on('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_lines');
    }
};
