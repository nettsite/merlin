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
        Schema::table('parties', function (Blueprint $table) {
            $table->string('surname', 64)->after('name');
        });
        
        Schema::table('parties', function (Blueprint $table) {
            $table->string('full_name')->virtualAs('CONCAT_WS(" ",name, surname)')->after('surname');
            $table->string('full_address')->virtualAs('CONCAT_WS(", ",address, city, province, country_code, postal_code)')->after('postal_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->dropColumn('full_name');
            $table->dropColumn('full_address');
            $table->dropColumn('surname');
        });
    }
};
