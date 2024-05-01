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
        Schema::create('parties', function (Blueprint $table) {
            $table->string('name', 64);
            $table->string('email', 64);
            $table->string('phone', 16)->nullable();
            $table->string('address', 128)->nullable();
            $table->string('city', 16)->nullable();
            $table->string('province', 16)->nullable();
            $table->string('country_code', 2)->default('ZA');
            $table->string('postal_code', 4)->nullable();
            $table->tinyInteger('type')->default(0);
            $table->string('tax_number', 16)->nullable();
            $table->string('registration_number', 16)->nullable();

            $table->uuid('id')->primary();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
