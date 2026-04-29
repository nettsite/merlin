<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropIndex('media_model_type_model_id_index');
            $table->char('model_id', 36)->change();
            $table->index(['model_type', 'model_id']);
        });
    }
};
