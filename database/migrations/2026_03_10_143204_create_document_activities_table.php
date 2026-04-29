<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->uuid('user_id')->nullable(); // null = system action
            $table->string('activity_type'); // 'created', 'status_changed', 'line_added', etc.
            $table->string('description');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('activity_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_activities');
    }
};
