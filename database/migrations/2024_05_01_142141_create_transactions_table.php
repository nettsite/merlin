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
        Schema::create('transactions', function (Blueprint $table) {
            $table->string('description');
            $table->smallInteger('quantity')->default(1);
            $table->decimal('unit_price', 15, 2);
            $table->tinyInteger('discount_type')->default(0); // 0 = percentage, 1 = fixed
            $table->decimal('discount', 15, 2);

            $table->foreignUuid('document_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('transaction_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('account_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('contra_id')->constrained('accounts')->restrictOnDelete();            

            $table->uuid('id')->primary();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
