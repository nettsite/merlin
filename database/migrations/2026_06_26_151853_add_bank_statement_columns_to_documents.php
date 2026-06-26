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
        Schema::table('documents', function (Blueprint $table) {
            $table->uuid('bank_template_id')->nullable()->index()->after('contra_account_id');
            $table->foreign('bank_template_id')->references('id')->on('bank_templates')->nullOnDelete();
            $table->boolean('requires_review')->default(false)->after('bank_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['bank_template_id']);
            $table->dropColumn(['bank_template_id', 'requires_review']);
        });
    }
};
