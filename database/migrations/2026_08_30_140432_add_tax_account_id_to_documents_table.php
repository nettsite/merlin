<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->uuid('tax_account_id')->nullable()->index()->after('contra_account_id');
            $table->foreign('tax_account_id')->references('id')->on('accounts')->nullOnDelete();
        });
    }
};
