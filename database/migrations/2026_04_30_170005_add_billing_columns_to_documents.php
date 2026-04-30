<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->uuid('receivable_account_id')->nullable()->index()->after('payable_account_id');
            $table->foreign('receivable_account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->uuid('bank_account_id')->nullable()->index()->after('receivable_account_id');
            $table->foreign('bank_account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->uuid('payment_term_id')->nullable()->index()->after('bank_account_id');
            $table->foreign('payment_term_id')->references('id')->on('payment_terms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['receivable_account_id']);
            $table->dropForeign(['bank_account_id']);
            $table->dropForeign(['payment_term_id']);
            $table->dropColumn(['receivable_account_id', 'bank_account_id', 'payment_term_id']);
        });
    }
};
