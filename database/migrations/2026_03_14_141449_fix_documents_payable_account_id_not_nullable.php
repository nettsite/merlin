<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $apId = DB::table('accounts')
            ->where('code', config('documents.accounts.default_payable'))
            ->value('id');

        DB::table('documents')
            ->whereNull('payable_account_id')
            ->update(['payable_account_id' => $apId]);

        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['payable_account_id']);
            $table->foreignUuid('payable_account_id')->nullable(false)->change();
            $table->foreign('payable_account_id')->references('id')->on('accounts')->restrictOnDelete();
        });
    }
};
