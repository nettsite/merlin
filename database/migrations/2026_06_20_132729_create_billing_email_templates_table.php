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
        Schema::create('billing_email_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');         // 'invoice' | 'reminder'
            $table->string('name');
            $table->string('subject');
            $table->text('body');
            $table->integer('offset_days')->nullable(); // null for invoice; negative = before due
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_email_templates');
    }
};
