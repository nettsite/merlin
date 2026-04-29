<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table): void {
            $table->string('status')->default('active')->after('party_type');
        });

        // Migrate existing data
        DB::table('parties')->where('is_active', false)->update(['status' => 'inactive']);

        Schema::table('parties', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
