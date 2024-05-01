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
        Schema::create('contacts', function (Blueprint $table) {
            $table->string('name', 32);
            $table->string('surname', 32)->nullable();
            $table->string('email', 64);
            $table->string('phone', 16)->nullable();

            $table->uuid('id')->primary();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('contact_party', function (Blueprint $table) {
            $table->foreignUuid('contact_id')->constrained()->cascadeOnDelete();
            $table->uuid('party_id')->constrained()->cascadeOnDelete();

            $table->primary(['contact_id', 'party_id']);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
