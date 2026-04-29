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
        Schema::create('businesses', function (Blueprint $table) {
            $table->uuid('id')->primary(); // shares UUID with parties.id
            $table->string('business_type');
            $table->string('legal_name');
            $table->string('trading_name')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('website')->nullable();

            $table->foreign('id')->references('id')->on('parties')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
