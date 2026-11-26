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
        Schema::table('billing_email_templates', function (Blueprint $table): void {
            $table->uuid('nettmail_template_id')->nullable()->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billing_email_templates', function (Blueprint $table): void {
            $table->dropColumn('nettmail_template_id');
        });
    }
};
