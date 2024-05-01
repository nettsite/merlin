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
        Schema::create('companies', function (Blueprint $table) {
            $table->string('name', 128)->unique();
            // $table->string('email', 128)->unique();
            // $table->string('phone', 32)->nullable();
            // $table->string('website', 128)->nullable(); 
            // $table->string('address', 128)->nullable();
            // $table->string('city', 128)->nullable();
            // $table->string('state', 128)->nullable();
            // $table->string('zip', 128)->nullable();
            // $table->string('country', 128)->nullable();
            // $table->string('logo', 128)->nullable();
            
            $table->uuid('id')->primary();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('company_user', function (Blueprint $table) {
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_user');
        Schema::dropIfExists('companies');
    }
};
