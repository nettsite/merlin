<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['bank_account_id']);
            $table->renameColumn('bank_account_id', 'contra_account_id');
            $table->foreign('contra_account_id')->references('id')->on('accounts')->nullOnDelete();
        });
    }
};
