<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('party_relationships', function (Blueprint $table) {
            $table->uuid('default_receivable_account_id')->nullable()->index()->after('default_payable_account_id');
            $table->foreign('default_receivable_account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->uuid('payment_term_id')->nullable()->index()->after('default_receivable_account_id');
            $table->foreign('payment_term_id')->references('id')->on('payment_terms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('party_relationships', function (Blueprint $table) {
            $table->dropForeign(['default_receivable_account_id']);
            $table->dropForeign(['payment_term_id']);
            $table->dropColumn(['default_receivable_account_id', 'payment_term_id']);
        });
    }
};
