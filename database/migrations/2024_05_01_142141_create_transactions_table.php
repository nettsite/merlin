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
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->smallInteger('quantity')->default(1);
            $table->decimal('unit_price', 15, 2);
            $table->tinyInteger('discount_type')->default(0); // 1 = percentage, 2 = fixed
            $table->decimal('discount', 15, 2)->nullable();
            
            $table->foreignUuid('document_id')->constrained()->restrictOnDelete();           

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
