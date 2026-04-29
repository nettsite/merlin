<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->nullableUuidMorphs('loggable');
            $table->integer('prompt_tokens')->default(0);
            $table->integer('completion_tokens')->default(0);
            $table->string('model');
            $table->decimal('confidence', 5, 4)->nullable();
            $table->integer('duration_ms')->nullable();
            $table->json('request_payload');
            $table->json('response_payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }
};
