<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // payable_account_id is only relevant to purchase invoices.
    // Sales invoices use receivable_account_id instead, so the NOT NULL
    // constraint introduced for purchase invoices would reject them.
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropForeign(['payable_account_id']);
            $table->uuid('payable_account_id')->nullable()->change();
            $table->foreign('payable_account_id')->references('id')->on('accounts')->nullOnDelete();
        });
    }
};
