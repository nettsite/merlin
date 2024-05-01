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
        Schema::create('documents', function (Blueprint $table) {
            $table->char('role', 1); // I = Invoice, R = Receipt, D = Debit Note, C = Credit Note
            $table->string('number');
            $table->foreignUuid('party_id')->constrained()->restrictOnDelete();
            $table->date('due_date');
            $table->char('status', 1); // C = Cancelled, D = Draft, S = Sent, P = Paid
            $table->boolean('recurring')->default(false);
            $table->char('frequency', 1)->nullable(); // D = Daily, W = Weekly, M = Monthly, Y = Yearly
            $table->date('next_date')->nullable();

            $table->uuid('id')->primary();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['role', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
