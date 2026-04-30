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
        Schema::create('party_relationships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('party_id')->constrained('parties')->cascadeOnDelete();
            $table->string('relationship_type');
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['party_id', 'relationship_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_relationships');
    }
};
