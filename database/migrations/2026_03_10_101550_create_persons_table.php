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
        Schema::create('persons', function (Blueprint $table) {
            $table->uuid('id')->primary(); // shares UUID with parties.id
            $table->string('first_name');
            $table->string('last_name');
            $table->string('title')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile')->nullable();
            $table->string('direct_line')->nullable();

            $table->foreign('id')->references('id')->on('parties')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persons');
    }
};
