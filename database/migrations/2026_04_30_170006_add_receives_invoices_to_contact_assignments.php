<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_assignments', function (Blueprint $table) {
            $table->boolean('receives_invoices')->default(false)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('contact_assignments', function (Blueprint $table) {
            $table->dropColumn('receives_invoices');
        });
    }
};
