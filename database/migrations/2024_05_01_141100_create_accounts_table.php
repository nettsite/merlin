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
        Schema::create('accounts', function (Blueprint $table) {
            $table->string('name');
            $table->char('role', 1); // A = Asset, L = Liability, Q = Equity, I = Income, C = Cost of Sales, E = Expense
            $table->string('number');
            $table->foreignUuid('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();
            
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
        Schema::dropIfExists('accounts');
    }
};
