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
        Schema::create('subscription_services', function (Blueprint $table) {
            $table->string('service', 100)->unique();
            $table->string('description', 255)->nullable();
            $table->json('pricing'); // { "monthly": 100, "yearly": 1000 } etc
            $table->json('features')->nullable(); // { "feature1": "description1", "feature2": "description2" } etc
            $table->json('limits')->nullable(); // { "limit1": "description1", "limit2": "description2" } etc
            $table->string('cancellation_policy', 255)->nullable();

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
        Schema::dropIfExists('subscription_services');
    }
};
