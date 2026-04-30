<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_terms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('rule');
            $table->unsignedInteger('days')->nullable();
            $table->unsignedInteger('day_of_month')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_terms');
    }
};
