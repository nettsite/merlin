<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_invoices', function (Blueprint $table): void {
            // When false, generated invoices stay in draft for manual review
            // instead of being emailed to the client immediately.
            $table->boolean('auto_send')->default(true)->after('currency');
        });
    }
};
