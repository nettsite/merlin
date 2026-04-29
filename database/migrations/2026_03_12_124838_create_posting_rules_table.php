<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posting_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('party_id')->nullable()->index();
            $table->foreign('party_id')->references('id')->on('parties')->nullOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('conditions');
            $table->json('actions');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_matched_at')->nullable();
            $table->unsignedInteger('match_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posting_rules');
    }
};
