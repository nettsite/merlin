<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('title');
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->timestamp('triggered_at');
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'cleared_at']);
        });
    }
};
